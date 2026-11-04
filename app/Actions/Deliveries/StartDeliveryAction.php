<?php

namespace App\Actions\Deliveries;

use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\Actions\Vehicles\StartVehicleUsageAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryLoad;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Services\Deliveries\DeliveryLoadUnits;
use App\Services\IdentityAndAccess\AccessControl;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Inventory\EggPresentationCatalog;
use Illuminate\Support\Facades\DB;

final readonly class StartDeliveryAction
{
    public function __construct(
        private RecordEggStockTransactionAction $stock,
        private AuditRecorder $audit,
        private StartVehicleUsageAction $startVehicleUsage,
        private DeliveryLoadUnits $units,
        private EggPresentationCatalog $catalog,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, array $data, string $idempotencyKey): Delivery
    {
        if ($actor->usesDevicePin()) {
            app(ProductionUnitAccess::class)->assertUnit((int) ($data['production_unit_id'] ?? $actor->production_unit_id), $actor);
            $data['production_unit_id'] = $actor->production_unit_id;
        }
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $startedWithoutLoad = (bool) ($data['start_without_load'] ?? false);

        return DB::transaction(function () use ($actor, $data, $idempotencyKey, $requestHash, $startedWithoutLoad): Delivery {
            $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            app(AccessControl::class)->assertCurrentSession($actor);
            $existing = Delivery::query()->where('start_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $legacyData = $data;
                unset($legacyData['production_unit_id']);
                $legacyHash = hash('sha256', json_encode($legacyData, JSON_THROW_ON_ERROR));
                if ($existing->driver_id !== $actor->getKey()
                    || ($existing->start_request_hash !== $requestHash
                        && ! (isset($data['production_unit_id']) && $existing->start_request_hash === $legacyHash
                            && $existing->production_unit_id === $data['production_unit_id']))) {
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

            if (! $startedWithoutLoad) {
                $this->catalog->lock();
            }
            $load = $startedWithoutLoad ? ['quantity' => 0, 'items' => []] : $this->units->calculate($data);
            $unit = $startedWithoutLoad ? null : $this->productionUnit((int) $data['production_unit_id']);
            $vehicle = Vehicle::query()->where('public_id', $data['vehicle_id'])->firstOrFail();
            $vehicleSnapshot = $vehicle->snapshot();
            $startedAt = now();
            $delivery = Delivery::query()->create([
                'driver_id' => $actor->getKey(),
                'vehicle_id' => $vehicle->getKey(),
                'vehicle_snapshot' => $vehicleSnapshot,
                'production_unit_id' => $unit?->getKey() ?? ($actor->usesDevicePin() ? $actor->production_unit_id : null),
                'started_without_load' => $startedWithoutLoad,
                'vehicle_reference' => $vehicleSnapshot['plate'],
                'status' => DeliveryStatus::Active,
                'loaded_quantity' => $load['quantity'],
                'returned_quantity' => 0,
                'start_idempotency_key' => $idempotencyKey,
                'start_request_hash' => $requestHash,
                'started_at' => $startedAt,
            ]);

            if ($unit !== null) {
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
                    'production_unit_id' => $unit->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'quantity' => $load['quantity'],
                    'items' => $load['items'],
                    'request_hash' => $requestHash,
                    'type' => 'initial',
                    'occurred_at' => $startedAt,
                    'created_by' => $actor->getKey(),
                ]);
            }

            $usage = $this->startVehicleUsage->execute($vehicle, $actor, [
                'purpose' => 'delivery',
                'odometer_km' => $data['odometer_km'] ?? null,
            ], $idempotencyKey, $delivery->getKey());
            $delivery->forceFill(['vehicle_usage_id' => $usage->getKey()])->save();

            $this->audit->record(AuditEntryData::forSubject(
                subject: $delivery,
                actor: $actor,
                logName: 'deliveries',
                event: 'delivery_started',
                description: 'Reparto iniciado',
                operationId: $idempotencyKey,
                source: 'delivery',
                properties: [
                    'production_unit_id' => $unit?->getKey(),
                    'loaded_quantity' => $load['quantity'],
                    'started_without_load' => $startedWithoutLoad,
                    'vehicle_reference' => $vehicleSnapshot['plate'],
                    'vehicle_id' => $vehicle->public_id,
                    'result' => 'success',
                ],
            ));

            return $this->load($delivery);
        }, 3);
    }

    private function productionUnit(int $productionUnitId): ProductionUnit
    {
        $unit = ProductionUnit::query()->where('status', ProductionUnitStatus::Active)->whereKey($productionUnitId)->lockForUpdate()->first();
        if ($unit === null) {
            throw new DeliveryConflict('La unidad productiva indicada no está operativa.');
        }

        return $unit;
    }

    private function load(Delivery $delivery): Delivery
    {
        return $delivery->load([
            'driver:id,name',
            'productionUnit:id,name,latitude,longitude',
            'loads.productionUnit:id,name,latitude,longitude',
            'returnProductionUnit:id,name,latitude,longitude',
            'stops',
            'accountMovements.account.customer', 'accountMovements.operation', 'accountMovements.delivery', 'accountMovements.reversal', 'accountMovements.original',
            'latestLocation',
            'vehicleUsage',
        ]);
    }
}
