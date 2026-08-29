<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\StockReservationLine;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Enums\StockReservationStatus;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Inventory\Domain\Exceptions\InventoryIdempotentReplay;
use App\Modules\Inventory\Domain\ValueObjects\InventoryQuantity;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries\GetActiveProductQuery;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ReserveStockAction
{
    public function __construct(
        private RecordInventoryMovementAction $recordMovement,
        private AuditRecorder $auditRecorder,
        private GetActiveProductQuery $getActiveProduct,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor): StockReservation
    {
        $inputLines = $attributes['lines'];
        usort($inputLines, static fn (array $left, array $right): int => [$left['stock_location_id'], $left['product_id']] <=> [$right['stock_location_id'], $right['product_id']]);
        $keys = array_map(static fn (array $line): string => $line['product_id'].':'.$line['stock_location_id'], $inputLines);
        if (count($keys) !== count(array_unique($keys))) {
            throw new InventoryConflict('No puedes repetir el mismo producto y ubicación en una reserva.');
        }
        $payload = [
            'type' => InventoryMovementType::Reservation->value,
            'lines' => array_map(static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'stock_location_id' => (int) $line['stock_location_id'],
                'quantity' => (string) $line['quantity'],
            ], $inputLines),
            'reference_type' => $attributes['reference_type'] ?? null,
            'reference_id' => $attributes['reference_id'] ?? null,
        ];
        $operationId = (string) $attributes['idempotency_key'];
        $expectedHash = (new InventoryMovementCommand(
            type: InventoryMovementType::Reservation,
            lines: [],
            operationId: $operationId,
            idempotencyPayload: $payload,
        ))->requestHash();
        $existing = InventoryMovement::query()->where('operation_id', $operationId)->with('reservation')->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $expectedHash) {
                throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
            }
            if ($existing->reservation === null) {
                throw new InventoryConflict('La operación idempotente no tiene una reserva asociada.');
            }

            return $existing->reservation->load(['lines.product', 'lines.stockLocation']);
        }

        try {
            return DB::transaction(function () use ($attributes, $inputLines, $actor, $payload, $operationId): StockReservation {
                $products = [];
                $normalizedLines = [];
                foreach ($inputLines as $line) {
                    $product = $this->getActiveProduct->execute((int) $line['product_id']);
                    if ($product === null || ! $product->stockTracked) {
                        throw new InventoryConflict('El producto indicado no está activo o no controla stock.');
                    }

                    try {
                        $quantity = InventoryQuantity::from((string) $line['quantity'], $product->baseUnit, false);
                    } catch (InvalidArgumentException $exception) {
                        throw new InventoryConflict($exception->getMessage(), previous: $exception);
                    }

                    if ($quantity->isZero()) {
                        throw new InventoryConflict('La cantidad reservada debe ser mayor que cero.');
                    }

                    $products[(int) $line['product_id']] = $product;
                    $normalizedLines[] = [
                        ...$line,
                        'quantity' => $quantity->toString(),
                    ];
                }

                $reservation = new StockReservation;
                $reservation->forceFill([
                    'reference_type' => $attributes['reference_type'] ?? null,
                    'reference_id' => $attributes['reference_id'] ?? null,
                    'status' => StockReservationStatus::Active,
                    'created_by' => $actor->getKey(),
                ])->save();

                foreach ($normalizedLines as $line) {
                    $reservationLine = new StockReservationLine;
                    $reservationLine->forceFill([
                        'stock_reservation_id' => $reservation->getKey(),
                        'product_id' => (int) $line['product_id'],
                        'stock_location_id' => (int) $line['stock_location_id'],
                        'unit' => $products[(int) $line['product_id']]->baseUnit->value,
                        'reserved_quantity' => (string) $line['quantity'],
                        'released_quantity' => '0',
                        'consumed_quantity' => '0',
                    ])->save();
                }

                $movement = $this->recordMovement->execute(new InventoryMovementCommand(
                    type: InventoryMovementType::Reservation,
                    lines: array_map(static fn (array $line): array => [
                        'product_id' => (int) $line['product_id'],
                        'stock_location_id' => (int) $line['stock_location_id'],
                        'on_hand_delta' => '0',
                        'reserved_delta' => (string) $line['quantity'],
                    ], $normalizedLines),
                    operationId: $operationId,
                    stockReservationId: (int) $reservation->getKey(),
                    referenceType: $attributes['reference_type'] ?? null,
                    referenceId: $attributes['reference_id'] ?? null,
                    idempotencyPayload: $payload,
                ), $actor);

                if ((int) $movement->stock_reservation_id !== (int) $reservation->getKey()) {
                    throw new InventoryIdempotentReplay($movement);
                }

                $reservation->forceFill(['status' => StockReservationStatus::Active])->save();
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $reservation,
                    actor: $actor,
                    logName: 'inventory',
                    event: 'stock_reservation_created',
                    description: 'Reserva de stock creada',
                    operationId: $operationId,
                    traceId: null,
                    source: 'api',
                    upId: null,
                    properties: ['movement_id' => $movement->getKey(), 'result' => 'success'],
                ));

                return $reservation->load(['lines.product', 'lines.stockLocation']);
            }, 3);
        } catch (InventoryIdempotentReplay $replay) {
            $replayedReservation = $replay->movement->reservation;
            if ($replayedReservation === null) {
                throw new InventoryConflict('La operación idempotente no tiene una reserva asociada.');
            }

            return $replayedReservation->load(['lines.product', 'lines.stockLocation']);
        }
    }
}
