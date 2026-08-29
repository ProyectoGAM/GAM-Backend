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
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ConsumeStockReservationAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement, private AuditRecorder $auditRecorder) {}

    /** @param array<string, mixed> $attributes */
    public function execute(StockReservation $reservation, array $attributes, User $actor): StockReservation
    {
        $inputLines = $attributes['lines'] ?? [];
        usort($inputLines, static fn (array $left, array $right): int => ($left['reservation_line_id'] ?? 0) <=> ($right['reservation_line_id'] ?? 0));
        $payload = ['type' => InventoryMovementType::Consumption->value, 'reservation_id' => (int) $reservation->getKey(), 'lines' => $inputLines];
        $operationId = (string) $attributes['idempotency_key'];
        $expectedHash = (new InventoryMovementCommand(type: InventoryMovementType::Consumption, lines: [], operationId: $operationId, idempotencyPayload: $payload))->requestHash();
        $existing = InventoryMovement::query()->where('operation_id', $operationId)->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $expectedHash) {
                throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
            }

            return $reservation->load(['lines.product', 'lines.stockLocation']);
        }

        return DB::transaction(function () use ($reservation, $actor, $inputLines, $payload, $operationId, $expectedHash): StockReservation {
            $locked = StockReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            $existingInsideTransaction = InventoryMovement::query()->where('operation_id', $operationId)->first();
            if ($existingInsideTransaction !== null) {
                if ($existingInsideTransaction->request_hash !== $expectedHash) {
                    throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
                }

                return $locked->load(['lines.product', 'lines.stockLocation']);
            }
            if (in_array($locked->status, [StockReservationStatus::Released, StockReservationStatus::Consumed], true)) {
                throw new InventoryConflict('La reserva ya no admite consumos.');
            }
            $linesQuery = StockReservationLine::query()->where('stock_reservation_id', $locked->getKey());
            $reservationLines = $inputLines === [] ? $linesQuery->lockForUpdate()->get() : $linesQuery->whereIn('id', array_column($inputLines, 'reservation_line_id'))->lockForUpdate()->get();
            if ($reservationLines->count() !== ($inputLines === [] ? $locked->lines()->count() : count($inputLines))) {
                throw new InventoryConflict('Una línea de reserva no pertenece a la reserva indicada.');
            }
            $requested = collect($inputLines)->keyBy('reservation_line_id');
            $movementLines = [];
            foreach ($reservationLines as $line) {
                $remaining = BigDecimal::of((string) $line->reserved_quantity)->minus((string) $line->released_quantity)->minus((string) $line->consumed_quantity);
                $amount = $requested->has($line->getKey()) ? BigDecimal::of((string) $requested->get($line->getKey())['quantity']) : $remaining;
                if ($amount->isNegative() || $amount->isZero() || $amount->isGreaterThan($remaining)) {
                    throw new InventoryConflict('La cantidad a consumir supera la cantidad aún reservada.');
                }
                $line->forceFill(['consumed_quantity' => (string) BigDecimal::of((string) $line->consumed_quantity)->plus($amount)])->save();
                $movementLines[] = ['product_id' => (int) $line->product_id, 'stock_location_id' => (int) $line->stock_location_id, 'on_hand_delta' => '-'.(string) $amount, 'reserved_delta' => '-'.(string) $amount];
            }
            $movement = $this->recordMovement->execute(new InventoryMovementCommand(type: InventoryMovementType::Consumption, lines: $movementLines, operationId: $operationId, stockReservationId: (int) $locked->getKey(), idempotencyPayload: $payload), $actor);
            $locked->forceFill(['status' => $this->statusAfterChange($locked)])->save();
            $this->auditRecorder->record(AuditEntryData::forSubject(subject: $locked, actor: $actor, logName: 'inventory', event: 'stock_reservation_consumed', description: 'Stock reservado consumido', operationId: $operationId, traceId: null, source: 'api', upId: null, properties: ['movement_id' => $movement->getKey(), 'result' => 'success']));

            return $locked->load(['lines.product', 'lines.stockLocation']);
        }, 3);
    }

    private function statusAfterChange(StockReservation $reservation): StockReservationStatus
    {
        $remaining = $reservation->lines()->get()->filter(fn (StockReservationLine $line): bool => BigDecimal::of((string) $line->reserved_quantity)->minus((string) $line->released_quantity)->minus((string) $line->consumed_quantity)->isPositive())->isEmpty();
        $allConsumed = $reservation->lines()->get()->every(fn (StockReservationLine $line): bool => BigDecimal::of((string) $line->consumed_quantity)->compareTo((string) $line->reserved_quantity) === 0);

        return $allConsumed ? StockReservationStatus::Consumed : ($remaining ? StockReservationStatus::Released : StockReservationStatus::PartiallyConsumed);
    }
}
