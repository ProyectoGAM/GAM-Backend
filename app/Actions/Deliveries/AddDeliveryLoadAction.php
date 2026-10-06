<?php

namespace App\Actions\Deliveries;

use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryLoad;
use App\Models\User;
use App\Services\Deliveries\DeliveryLoadUnits;
use Illuminate\Support\Facades\DB;

final readonly class AddDeliveryLoadAction
{
    public function __construct(
        private RecordEggStockTransactionAction $stock,
        private AuditRecorder $audit,
        private DeliveryLoadUnits $units,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Delivery $delivery, User $actor, array $data, string $idempotencyKey): Delivery
    {
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($delivery, $actor, $data, $idempotencyKey, $requestHash): Delivery {
            $locked = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->driver_id !== $actor->getKey()) {
                throw new DeliveryConflict('No puedes cargar huevos en otro reparto.');
            }

            $existing = DeliveryLoad::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->delivery_id !== $locked->getKey()
                    || $existing->type !== 'additional'
                    || ($existing->request_hash !== null && $existing->request_hash !== $requestHash)
                    || ($existing->request_hash === null && $existing->quantity !== ($data['quantity'] ?? null))) {
                    throw new DeliveryConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $this->load($locked);
            }
            if ($locked->status !== DeliveryStatus::Active) {
                throw new DeliveryConflict('El reparto ya está cerrado.');
            }
            $load = $this->units->calculate($data);
            $quantity = $load['quantity'];
            if ($quantity > 2147483647 - $locked->loaded_quantity) {
                throw new DeliveryConflict('La carga total supera el máximo permitido.');
            }

            $this->stock->execute(
                unit: $locked->productionUnit()->lockForUpdate()->firstOrFail(),
                type: 'distribution_preparation',
                quantity: $quantity,
                operationId: $idempotencyKey,
                actor: $actor,
                reason: 'Carga adicional del reparto '.$locked->public_id,
                referenceType: 'delivery',
                referenceId: $locked->public_id,
                sign: -1,
                source: 'delivery',
            );

            // TODO(Notas3/M13): integrar tipos comerciales y fechas de recolección.
            DeliveryLoad::query()->create([
                'delivery_id' => $locked->getKey(),
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'items' => $load['items'],
                'request_hash' => $requestHash,
                'type' => 'additional',
                'created_by' => $actor->getKey(),
            ]);
            $locked->forceFill(['loaded_quantity' => $locked->loaded_quantity + $quantity])->save();

            $this->audit->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'deliveries',
                event: 'delivery_load_added',
                description: 'Carga adicional registrada',
                operationId: $idempotencyKey,
                source: 'delivery',
                properties: ['quantity' => $quantity, 'result' => 'success'],
            ));

            return $this->load($locked);
        }, 3);
    }

    private function load(Delivery $delivery): Delivery
    {
        return $delivery->load(['driver:id,name', 'productionUnit:id,name,latitude,longitude', 'loads', 'stops', 'latestLocation']);
    }
}
