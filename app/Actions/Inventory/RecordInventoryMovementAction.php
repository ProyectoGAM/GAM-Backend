<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\StockLocationStatus;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\Inventory\InventoryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Queries\SuppliersAndCatalogs\GetActiveSupplierQuery;
use App\ValueObjects\Inventory\InventoryQuantity;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RecordInventoryMovementAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private GetActiveSupplierQuery $getActiveSupplier,
    ) {}

    public function execute(InventoryMovementCommand $command, User $actor, string $source = 'api'): InventoryMovement
    {
        $legacyRequestHash = $this->legacyRequestHash($command);
        $normalizedCommand = $this->normalizeCommand($command);
        $requestHash = $normalizedCommand->requestHash();
        $existing = $this->existingMovement($normalizedCommand->operationId);

        if ($existing !== null) {
            return $this->resolveReplay($existing, $requestHash, $legacyRequestHash);
        }

        try {
            return DB::transaction(function () use ($normalizedCommand, $actor, $requestHash, $legacyRequestHash, $source): InventoryMovement {
                $existingInsideTransaction = $this->existingMovement($normalizedCommand->operationId);
                if ($existingInsideTransaction !== null) {
                    return $this->resolveReplay($existingInsideTransaction, $requestHash, $legacyRequestHash);
                }

                if ($normalizedCommand->lines === []) {
                    throw new InventoryConflict('La operación debe contener al menos una línea.');
                }

                $lineKeys = [];
                $locationIds = [];
                $normalizedLines = [];
                $productIds = [];
                foreach ($normalizedCommand->lines as $line) {
                    $key = $line['product_id'].':'.$line['stock_location_id'];
                    if (isset($lineKeys[$key])) {
                        throw new InventoryConflict('No puedes repetir el mismo producto y ubicación en una operación.');
                    }
                    $lineKeys[$key] = true;
                    $productIds[$line['product_id']] = true;
                }
                $products = Product::query()
                    ->whereIn('id', array_keys($productIds))
                    ->orderBy('id')
                    ->sharedLock()
                    ->get()
                    ->keyBy('id');

                foreach ($normalizedCommand->lines as $line) {
                    /** Bloquea los productos antes de consultar su estado y unidad. */
                    $product = $products->get($line['product_id']);
                    if ($product === null || $product->status->value !== 'active' || ! $product->stock_tracked) {
                        throw new InventoryConflict('El producto indicado no está activo o no controla stock.');
                    }
                    if ($product->system_key === 'generic_egg' && ! $normalizedCommand->eggAccountOperation) {
                        throw new InventoryConflict('El producto técnico Huevo sólo puede moverse desde el módulo de stock de huevos.');
                    }

                    try {
                        $onHandDelta = InventoryQuantity::from(
                            (string) $line['on_hand_delta'],
                            $product->base_unit,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new InventoryConflict($exception->getMessage(), previous: $exception);
                    }

                    if ($onHandDelta->isZero()) {
                        throw new InventoryConflict('La operación debe modificar al menos un saldo.');
                    }

                    $isEggAccount = EggStockAccount::query()
                        ->where('product_id', $line['product_id'])
                        ->where('stock_location_id', $line['stock_location_id'])
                        ->exists();
                    if ($normalizedCommand->eggAccountOperation && ($product->system_key !== 'generic_egg' || ! $isEggAccount)) {
                        throw new InventoryConflict('La operación técnica sólo puede utilizar una cuenta válida de huevos.');
                    }
                    if ($isEggAccount && ! $normalizedCommand->eggAccountOperation) {
                        throw new InventoryConflict('Las cuentas técnicas de huevos sólo pueden modificarse desde el módulo de huevos.');
                    }

                    $normalizedLines[] = [
                        ...$line,
                        'on_hand_delta' => $onHandDelta->toString(),
                    ];
                    $locationIds[$line['stock_location_id']] = true;
                }

                if ($normalizedCommand->supplierId !== null && $this->getActiveSupplier->execute($normalizedCommand->supplierId) === null) {
                    throw new InventoryConflict('El proveedor indicado no está activo.');
                }

                $locations = StockLocation::query()
                    ->whereIn('id', array_keys($locationIds))
                    ->with('poultryHouse')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($locations->count() !== count($locationIds) || $locations->contains(fn (StockLocation $location): bool => $location->status !== StockLocationStatus::Active)) {
                    throw new InventoryConflict('Una de las ubicaciones indicadas no está activa.');
                }

                foreach ($normalizedLines as $line) {
                    $product = $products->get($line['product_id']);
                    $location = $locations->get($line['stock_location_id']);
                    $hasPoultryHouseLink = $location->poultry_house_id !== null;
                    $isFeedLocation = $hasPoultryHouseLink
                        && $location->poultryHouse?->type === PoultryHouseType::Feed;
                    $isRawMaterial = $product->kind === ProductKind::RawMaterial;

                    if ($hasPoultryHouseLink && ! $isFeedLocation) {
                        throw new InventoryConflict('La ubicación vinculada no corresponde a una planta de ración.');
                    }

                    if ($isFeedLocation !== $isRawMaterial) {
                        throw new InventoryConflict($isFeedLocation
                            ? 'Una planta de ración sólo puede almacenar materias primas.'
                            : 'Las materias primas sólo pueden moverse en plantas de ración.');
                    }

                    if ($isFeedLocation && ($product->base_unit !== BaseUnit::Gram || ! $product->stock_tracked)) {
                        throw new InventoryConflict('Las materias primas de una planta de ración deben controlar stock en gramos.');
                    }
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
                        'allow_negative' => $normalizedCommand->eggAccountOperation
                            || ($locations->get($line['stock_location_id'])->poultryHouse?->type === PoultryHouseType::Feed
                                && $products->get($line['product_id'])->kind === ProductKind::RawMaterial),
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
                    $previousOnHand = BigDecimal::of((string) $balance->on_hand_quantity)->toScale(6);
                    $onHand = $previousOnHand->plus($line['on_hand_delta'])->toScale(6);
                    $product = $products->get($line['product_id']);
                    $location = $locations->get($line['stock_location_id']);
                    $isFeedRawMaterial = $location->poultry_house_id !== null
                        && $location->poultryHouse?->type === PoultryHouseType::Feed
                        && $product->kind === ProductKind::RawMaterial;
                    $allowNegative = $normalizedCommand->eggAccountOperation || $isFeedRawMaterial;
                    $previousAllowNegative = (bool) $balance->allow_negative;

                    try {
                        InventoryQuantity::from((string) $onHand, $product->base_unit);
                    } catch (InvalidArgumentException $exception) {
                        throw new InventoryConflict($exception->getMessage(), previous: $exception);
                    }

                    if ($onHand->isNegative() && ! $allowNegative) {
                        throw new InventoryConflict('El stock disponible resultante no puede ser negativo.');
                    }

                    $balance->forceFill([
                        'on_hand_quantity' => (string) $onHand,
                        'allow_negative' => $allowNegative,
                    ])->save();

                    if ($isFeedRawMaterial && ! $previousOnHand->isNegative() && $onHand->isNegative()) {
                        $this->auditRecorder->record(AuditEntryData::forSubject(
                            subject: $balance,
                            actor: $actor,
                            logName: 'inventory',
                            event: 'inventory_stock_became_negative',
                            description: 'El saldo de inventario pasó a ser negativo',
                            operationId: $normalizedCommand->operationId,
                            source: $source,
                            upId: $location->production_unit_id,
                            properties: [
                                'subject_snapshot' => [
                                    'product_id' => (int) $product->getKey(),
                                    'product_sku' => $product->sku,
                                    'product_name' => $product->name,
                                    'stock_location_id' => (int) $location->getKey(),
                                    'stock_location_name' => $location->name,
                                    'poultry_house_id' => (int) $location->poultry_house_id,
                                    'production_unit_id' => (int) $location->production_unit_id,
                                    'on_hand_quantity' => (string) $onHand,
                                ],
                                'product_id' => (int) $line['product_id'],
                                'stock_location_id' => (int) $line['stock_location_id'],
                                'previous_quantity' => (string) $previousOnHand,
                                'resulting_quantity' => (string) $onHand,
                                'result' => 'negative_balance',
                            ],
                            attributeChanges: [
                                'old' => [
                                    'on_hand_quantity' => (string) $previousOnHand,
                                    'allow_negative' => $previousAllowNegative,
                                ],
                                'new' => [
                                    'on_hand_quantity' => (string) $onHand,
                                    'allow_negative' => true,
                                ],
                            ],
                        ));
                    }
                }

                $movement = new InventoryMovement;
                $movement->forceFill([
                    'operation_id' => $normalizedCommand->operationId,
                    'request_hash' => $requestHash,
                    'type' => $normalizedCommand->type,
                    'supplier_id' => $normalizedCommand->supplierId,
                    'reference_type' => $normalizedCommand->referenceType,
                    'reference_id' => $normalizedCommand->referenceId,
                    'reason' => $normalizedCommand->reason,
                    'occurred_at' => $normalizedCommand->occurredAt === null ? now() : $normalizedCommand->occurredAt,
                    'created_by' => $actor->getKey(),
                    'reverses_movement_id' => $normalizedCommand->reversesMovementId,
                ])->save();

                foreach ($sortedLines as $line) {
                    $movementLine = new InventoryMovementLine;
                    $movementLine->forceFill([
                        'inventory_movement_id' => $movement->getKey(),
                        'product_id' => $line['product_id'],
                        'stock_location_id' => $line['stock_location_id'],
                        'unit' => $products->get($line['product_id'])->base_unit->value,
                        'on_hand_delta' => $line['on_hand_delta'],
                    ])->save();
                }

                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $movement,
                    actor: $actor,
                    logName: 'inventory',
                    event: 'inventory_movement_recorded',
                    description: 'Movimiento de inventario registrado',
                    operationId: $normalizedCommand->operationId,
                    traceId: null,
                    source: $source,
                    upId: null,
                    properties: [
                        'movement_type' => $normalizedCommand->type->value,
                        'supplier_id' => $normalizedCommand->supplierId,
                        'reference_type' => $normalizedCommand->referenceType,
                        'reference_id' => $normalizedCommand->referenceId,
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

            $existingAfterRace = $this->existingMovement($normalizedCommand->operationId);
            if ($existingAfterRace === null) {
                throw $exception;
            }

            return $this->resolveReplay($existingAfterRace, $requestHash, $legacyRequestHash);
        }
    }

    private function legacyRequestHash(InventoryMovementCommand $command): ?string
    {
        if ($command->idempotencyPayload !== null
            || array_filter($command->lines, static fn (array $line): bool => ($line['unit'] ?? null) !== null) !== []) {
            return null;
        }

        $legacyLines = array_map(static function (array $line): array {
            unset($line['unit']);

            return $line;
        }, $command->lines);

        return (new InventoryMovementCommand(
            type: $command->type,
            lines: $legacyLines,
            operationId: $command->operationId,
            supplierId: $command->supplierId,
            referenceType: $command->referenceType,
            referenceId: $command->referenceId,
            reason: $command->reason,
            occurredAt: $command->occurredAt,
            reversesMovementId: $command->reversesMovementId,
            eggAccountOperation: $command->eggAccountOperation,
        ))->requestHash();
    }

    private function normalizeCommand(InventoryMovementCommand $command): InventoryMovementCommand
    {
        if ($command->lines === []) {
            return $command;
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['product_id'],
            $command->lines,
        )));
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $lines = [];

        foreach ($command->lines as $line) {
            $product = $products->get((int) $line['product_id']);
            if ($product === null) {
                $lines[] = $line;

                continue;
            }

            $inputUnit = null;
            if (array_key_exists('unit', $line) && $line['unit'] !== null) {
                $inputUnit = BaseUnit::tryFrom((string) $line['unit']);
                if ($inputUnit === null) {
                    throw new InventoryConflict('La unidad de entrada no es válida.');
                }
            }

            try {
                $delta = InventoryQuantity::fromInput(
                    (string) $line['on_hand_delta'],
                    $inputUnit,
                    $product->base_unit,
                );
            } catch (InvalidArgumentException $exception) {
                throw new InventoryConflict($exception->getMessage(), previous: $exception);
            }

            // La unidad se convierte antes del hash y se omite para conservar
            // la compatibilidad con las operaciones históricas sin unidad.
            $canonicalLine = [
                ...$line,
                'on_hand_delta' => $delta->toString(),
            ];
            unset($canonicalLine['unit']);
            $lines[] = $canonicalLine;
        }

        return new InventoryMovementCommand(
            type: $command->type,
            lines: $lines,
            operationId: $command->operationId,
            supplierId: $command->supplierId,
            referenceType: $command->referenceType,
            referenceId: $command->referenceId,
            reason: $command->reason,
            occurredAt: $command->occurredAt,
            reversesMovementId: $command->reversesMovementId,
            idempotencyPayload: $command->idempotencyPayload,
            eggAccountOperation: $command->eggAccountOperation,
        );
    }

    private function existingMovement(string $operationId): ?InventoryMovement
    {
        return InventoryMovement::query()->where('operation_id', $operationId)->first();
    }

    private function resolveReplay(InventoryMovement $movement, string $requestHash, ?string $legacyRequestHash = null): InventoryMovement
    {
        if (! in_array($movement->request_hash, array_values(array_filter([$requestHash, $legacyRequestHash])), true)) {
            throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
        }

        return $movement->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23505';
    }
}
