<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Inventory\Domain\ValueObjects\InventoryQuantity;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries\GetActiveProductQuery;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries\GetActiveSupplierQuery;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RecordInventoryMovementAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private GetActiveProductQuery $getActiveProduct,
        private GetActiveSupplierQuery $getActiveSupplier,
    ) {}

    public function execute(InventoryMovementCommand $command, User $actor, string $source = 'api'): InventoryMovement
    {
        $requestHash = $command->requestHash();
        $existing = $this->existingMovement($command->operationId);

        if ($existing !== null) {
            return $this->resolveReplay($existing, $requestHash);
        }

        try {
            return DB::transaction(function () use ($command, $actor, $requestHash, $source): InventoryMovement {
                $existingInsideTransaction = $this->existingMovement($command->operationId);
                if ($existingInsideTransaction !== null) {
                    return $this->resolveReplay($existingInsideTransaction, $requestHash);
                }

                if ($command->lines === []) {
                    throw new InventoryConflict('La operación debe contener al menos una línea.');
                }

                $lineKeys = [];
                $productData = [];
                $locationIds = [];
                $normalizedLines = [];
                foreach ($command->lines as $line) {
                    $key = $line['product_id'].':'.$line['stock_location_id'];
                    if (isset($lineKeys[$key])) {
                        throw new InventoryConflict('No puedes repetir el mismo producto y ubicación en una operación.');
                    }
                    $lineKeys[$key] = true;
                    $productData[$line['product_id']] ??= $this->getActiveProduct->execute($line['product_id']);
                    if ($productData[$line['product_id']] === null || ! $productData[$line['product_id']]->stockTracked) {
                        throw new InventoryConflict('El producto indicado no está activo o no controla stock.');
                    }

                    try {
                        $onHandDelta = InventoryQuantity::from(
                            (string) ($line['on_hand_delta'] ?? '0'),
                            $productData[$line['product_id']]->baseUnit,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new InventoryConflict($exception->getMessage(), previous: $exception);
                    }

                    if ($onHandDelta->isZero()) {
                        throw new InventoryConflict('La operación debe modificar al menos un saldo.');
                    }

                    $normalizedLines[] = [
                        ...$line,
                        'on_hand_delta' => $onHandDelta->toString(),
                    ];
                    $locationIds[$line['stock_location_id']] = true;
                }

                if ($command->supplierId !== null && $this->getActiveSupplier->execute($command->supplierId) === null) {
                    throw new InventoryConflict('El proveedor indicado no está activo.');
                }

                $locations = StockLocation::query()
                    ->whereIn('id', array_keys($locationIds))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($locations->count() !== count($locationIds) || $locations->contains(fn (StockLocation $location): bool => $location->status->value !== 'active')) {
                    throw new InventoryConflict('Una de las ubicaciones indicadas no está activa.');
                }

                $sortedLines = $normalizedLines;
                usort($sortedLines, static fn (array $left, array $right): int => [$left['stock_location_id'], $left['product_id']] <=> [$right['stock_location_id'], $right['product_id']]);
                $now = now();
                StockBalance::query()->insertOrIgnore(array_map(
                    static fn (array $line): array => [
                        'product_id' => $line['product_id'],
                        'stock_location_id' => $line['stock_location_id'],
                        'on_hand_quantity' => '0.000000',
                        'minimum_quantity' => '0.000000',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $sortedLines,
                ));

                $balances = [];
                foreach ($sortedLines as $line) {
                    $balance = StockBalance::query()
                        ->where('product_id', $line['product_id'])
                        ->where('stock_location_id', $line['stock_location_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $balances[$line['product_id'].':'.$line['stock_location_id']] = $balance;
                }

                foreach ($sortedLines as $line) {
                    $balance = $balances[$line['product_id'].':'.$line['stock_location_id']];
                    $onHand = BigDecimal::of((string) $balance->on_hand_quantity)->plus($line['on_hand_delta'])->toScale(6);

                    if ($onHand->isNegative()) {
                        throw new InventoryConflict('El stock disponible resultante no puede ser negativo.');
                    }

                    $balance->forceFill([
                        'on_hand_quantity' => (string) $onHand,
                    ])->save();
                }

                $movement = new InventoryMovement;
                $movement->forceFill([
                    'operation_id' => $command->operationId,
                    'request_hash' => $requestHash,
                    'type' => $command->type,
                    'supplier_id' => $command->supplierId,
                    'reference_type' => $command->referenceType,
                    'reference_id' => $command->referenceId,
                    'reason' => $command->reason,
                    'occurred_at' => $command->occurredAt === null ? now() : $command->occurredAt,
                    'created_by' => $actor->getKey(),
                ])->save();

                foreach ($sortedLines as $line) {
                    $movementLine = new InventoryMovementLine;
                    $movementLine->forceFill([
                        'inventory_movement_id' => $movement->getKey(),
                        'product_id' => $line['product_id'],
                        'stock_location_id' => $line['stock_location_id'],
                        'unit' => $productData[$line['product_id']]->baseUnit->value,
                        'on_hand_delta' => $line['on_hand_delta'],
                    ])->save();
                }

                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $movement,
                    actor: $actor,
                    logName: 'inventory',
                    event: 'inventory_movement_recorded',
                    description: 'Movimiento de inventario registrado',
                    operationId: $command->operationId,
                    traceId: null,
                    source: $source,
                    upId: null,
                    properties: [
                        'movement_type' => $command->type->value,
                        'supplier_id' => $command->supplierId,
                        'reference_type' => $command->referenceType,
                        'reference_id' => $command->referenceId,
                        'line_count' => count($sortedLines),
                        'product_ids' => array_values(array_unique(array_column($sortedLines, 'product_id'))),
                        'stock_location_ids' => array_values(array_unique(array_column($sortedLines, 'stock_location_id'))),
                        'result' => 'success',
                    ],
                ));

                return $movement->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existingAfterRace = $this->existingMovement($command->operationId);
            if ($existingAfterRace === null) {
                throw $exception;
            }

            return $this->resolveReplay($existingAfterRace, $requestHash);
        }
    }

    private function existingMovement(string $operationId): ?InventoryMovement
    {
        return InventoryMovement::query()->where('operation_id', $operationId)->first();
    }

    private function resolveReplay(InventoryMovement $movement, string $requestHash): InventoryMovement
    {
        if ($movement->request_hash !== $requestHash) {
            throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
        }

        return $movement->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23505';
    }
}
