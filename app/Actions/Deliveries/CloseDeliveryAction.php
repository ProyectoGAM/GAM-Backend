<?php

namespace App\Actions\Deliveries;

use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CloseDeliveryAction
{
    public function __construct(
        private RecordEggStockTransactionAction $stock,
        private AuditRecorder $audit,
    ) {}

    /** @param array{returned_quantity:int,notes?:string|null} $data */
    public function execute(Delivery $delivery, User $actor, array $data, string $idempotencyKey): Delivery
    {
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($delivery, $actor, $data, $idempotencyKey, $requestHash): Delivery {
            $lockedDelivery = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedDelivery->driver_id !== $actor->getKey()) {
                throw new DeliveryConflict('No puedes cerrar otro reparto.');
            }
            if ($lockedDelivery->close_idempotency_key !== null) {
                if ($lockedDelivery->close_idempotency_key !== $idempotencyKey || $lockedDelivery->close_request_hash !== $requestHash) {
                    throw new DeliveryConflict('El reparto ya fue cerrado con otros datos.');
                }

                return $this->load($lockedDelivery);
            }
            if ($lockedDelivery->status !== DeliveryStatus::Active) {
                throw new DeliveryConflict('El reparto ya no está activo.');
            }

            $delivered = (int) DeliveryStop::query()
                ->where('delivery_id', $lockedDelivery->getKey())
                ->where('status', DeliveryStopStatus::Delivered)
                ->sum('delivered_quantity');
            $returned = $data['returned_quantity'];
            if ($delivered + $returned > $lockedDelivery->loaded_quantity) {
                throw new DeliveryConflict('La devolución supera la cantidad que quedó disponible.');
            }

            if ($returned > 0) {
                $this->stock->execute(
                    unit: $lockedDelivery->productionUnit()->lockForUpdate()->firstOrFail(),
                    type: 'distribution_return',
                    quantity: $returned,
                    operationId: $idempotencyKey,
                    actor: $actor,
                    reason: 'Devolución del reparto '.$lockedDelivery->public_id,
                    referenceType: 'delivery',
                    referenceId: $lockedDelivery->public_id,
                    sign: 1,
                    source: 'delivery',
                );
            }

            $lockedDelivery->forceFill([
                'status' => DeliveryStatus::Completed,
                'returned_quantity' => $returned,
                'close_idempotency_key' => $idempotencyKey,
                'close_request_hash' => $requestHash,
                'closed_at' => now(),
                'close_notes' => $data['notes'] ?? null,
            ])->save();

            $this->audit->record(AuditEntryData::forSubject(
                subject: $lockedDelivery,
                actor: $actor,
                logName: 'deliveries',
                event: 'delivery_closed',
                description: 'Reparto cerrado',
                operationId: $idempotencyKey,
                source: 'delivery',
                properties: [
                    'delivered_quantity' => $delivered,
                    'returned_quantity' => $returned,
                    'result' => 'success',
                ],
            ));

            return $this->load($lockedDelivery);
        }, 3);
    }

    private function load(Delivery $delivery): Delivery
    {
        return $delivery->load([
            'driver:id,name',
            'productionUnit:id,name,latitude,longitude',
            'loads',
            'stops',
            'latestLocation',
        ]);
    }
}
