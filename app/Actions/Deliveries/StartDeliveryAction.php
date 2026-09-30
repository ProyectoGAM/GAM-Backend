<?php

namespace App\Actions\Deliveries;

use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryLoad;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\Deliveries\DeliveryLoadUnits;
use App\Services\Deliveries\LocalDeliveryClientCatalog;
use Illuminate\Support\Facades\DB;

final readonly class StartDeliveryAction
{
    public function __construct(
        private RecordEggStockTransactionAction $stock,
        private AuditRecorder $audit,
        private LocalDeliveryClientCatalog $clientCatalog,
        private DeliveryLoadUnits $units,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, array $data, string $idempotencyKey): Delivery
    {
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $idempotencyKey, $requestHash): Delivery {
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $existing = Delivery::query()->where('start_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->start_request_hash !== $requestHash) {
                    throw new DeliveryConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $this->load($existing);
            }

            $active = Delivery::query()
                ->where('driver_id', $actor->getKey())
                ->where('status', DeliveryStatus::Active)
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                throw new DeliveryConflict('Ya tienes un reparto activo. Debes cerrarlo antes de iniciar otro.');
            }

            $load = $this->units->calculate($data);
            $unit = $this->productionUnit($data['production_unit_id'] ?? null);
            $vehicleReference = $data['vehicle_reference'] ?? $this->clientCatalog->vehicleReference();
            $delivery = Delivery::query()->create([
                'driver_id' => $actor->getKey(),
                'production_unit_id' => $unit->getKey(),
                'vehicle_reference' => $vehicleReference,
                'status' => DeliveryStatus::Active,
                'loaded_quantity' => $load['quantity'],
                'returned_quantity' => 0,
                'start_idempotency_key' => $idempotencyKey,
                'start_request_hash' => $requestHash,
                'started_at' => now(),
            ]);

            $this->stock->execute(
                unit: $unit,
                type: 'distribution_preparation',
                quantity: $load['quantity'],
                operationId: $idempotencyKey,
                actor: $actor,
                reason: 'Carga inicial del reparto '.$delivery->public_id,
                referenceType: 'delivery',
                referenceId: $delivery->public_id,
                sign: -1,
                source: 'delivery',
            );

            DeliveryLoad::query()->create([
                'delivery_id' => $delivery->getKey(),
                'idempotency_key' => $idempotencyKey,
                'quantity' => $load['quantity'],
                'items' => $load['items'],
                'request_hash' => $requestHash,
                'type' => 'initial',
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(AuditEntryData::forSubject(
                subject: $delivery,
                actor: $actor,
                logName: 'deliveries',
                event: 'delivery_started',
                description: 'Reparto iniciado',
                operationId: $idempotencyKey,
                source: 'delivery',
                properties: [
                    'production_unit_id' => $unit->getKey(),
                    'loaded_quantity' => $load['quantity'],
                    'vehicle_reference' => $vehicleReference,
                    'result' => 'success',
                ],
            ));

            return $this->load($delivery);
        }, 3);
    }

    private function productionUnit(?int $productionUnitId): ProductionUnit
    {
        $query = ProductionUnit::query()->where('status', ProductionUnitStatus::Active)->lockForUpdate();
        if ($productionUnitId !== null) {
            $query->whereKey($productionUnitId);
        } elseif (! app()->environment(['local', 'testing'])) {
            throw new DeliveryConflict('La unidad productiva es obligatoria fuera del entorno local.');
        }

        $unit = $query->first();
        if ($unit === null) {
            throw new DeliveryConflict('La unidad productiva indicada no está operativa.');
        }

        return $unit;
    }

    private function load(Delivery $delivery): Delivery
    {
        return $delivery->load([
            'driver:id,name',
            'productionUnit:id,name',
            'loads',
            'stops',
            'latestLocation',
        ]);
    }
}
