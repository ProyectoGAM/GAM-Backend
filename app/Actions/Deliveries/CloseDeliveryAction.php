<?php

namespace App\Actions\Deliveries;

use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\Actions\Vehicles\CloseVehicleUsageAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\Deliveries\DeliveryEggAlerts;
use App\Services\Deliveries\DeliveryFinancialSummary;
use App\Services\Deliveries\DeliveryUnitBalance;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CloseDeliveryAction
{
    public function __construct(
        private RecordEggStockTransactionAction $stock,
        private AuditRecorder $audit,
        private DeliveryUnitBalance $balances,
        private CloseVehicleUsageAction $closeVehicleUsage,
        private DeliveryEggAlerts $eggAlerts,
    ) {}

    /** @param array{returned_quantity?:int,returned_items?:list<array<string,mixed>>,return_production_unit_id?:int|null,notes?:string|null,odometer_km?:string|null} $data */
    public function execute(Delivery $delivery, User $actor, array $data, string $idempotencyKey): Delivery
    {
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($delivery, $actor, $data, $idempotencyKey, $requestHash): Delivery {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AccessControl::class)->assertCurrentSession($actor);
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
            $lockedDelivery->load(['loads.productionUnit:id,name,latitude,longitude', 'returnProductionUnit:id,name,latitude,longitude', 'stops']);
            $allocation = isset($data['returned_items'])
                ? ($data['returned_items'] === [] ? ['quantity' => 0, 'items' => []] : $this->balances->allocate($lockedDelivery, $data['returned_items']))
                : null;
            $returned = $allocation['quantity'] ?? $data['returned_quantity'];
            if ($returned > 0 && $lockedDelivery->loaded_quantity === 0) {
                throw new DeliveryConflict('El reparto debe tener una carga para registrar una devolución.');
            }
            $returnUnitId = $returned > 0 ? ($data['return_production_unit_id'] ?? null) : null;
            if ($returned > 0 && ($returnUnitId === null || ! $this->balances->canReturnTo($lockedDelivery, (int) $returnUnitId))) {
                throw ValidationException::withMessages(['return_production_unit_id' => 'Seleccioná una de las unidades productivas de las cargas para descargar todos los sobrantes.']);
            }
            $remaining = $lockedDelivery->loaded_quantity - $delivered - $returned;
            if ($remaining < 0) {
                throw new DeliveryConflict('Ajustá el reparto antes de cerrar. Las entregas y la devolución superan la carga registrada.');
            }
            if ($remaining > 0) {
                throw new DeliveryConflict('La descarga final debe incluir todos los huevos sobrantes para cerrar el reparto.');
            }

            $returnUnit = $returnUnitId === null ? null : ProductionUnit::query()->whereKey($returnUnitId)->lockForUpdate()->firstOrFail();
            if ($returnUnit !== null) {
                $this->stock->execute(
                    unit: $returnUnit,
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

            $lockedDelivery->load('accountMovements.account.customer');
            $snapshot = app(DeliveryFinancialSummary::class)->execute($lockedDelivery);
            $lockedDelivery->forceFill([
                'financial_snapshot' => $snapshot,
                'status' => DeliveryStatus::Completed,
                'returned_quantity' => $returned,
                'returned_items' => $allocation['items'] ?? null,
                'return_production_unit_id' => $returnUnitId,
                'close_idempotency_key' => $idempotencyKey,
                'close_request_hash' => $requestHash,
                'closed_at' => now(),
                'close_notes' => $data['notes'] ?? null,
            ])->save();

            if ($lockedDelivery->vehicle_usage_id !== null) {
                $this->closeVehicleUsage->execute($lockedDelivery->vehicleUsage, [
                    'odometer_km' => $data['odometer_km'] ?? null,
                ], $actor);
            }

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
                    'returned_items' => $allocation['items'] ?? null,
                    'return_production_unit_id' => $returnUnitId,
                    'result' => 'success',
                ],
            ));

            if ($returnUnit !== null) {
                $this->eggAlerts->surplus($lockedDelivery, $returnUnit, $returned);
            }
            $this->eggAlerts->negativeDeliveryBalance($lockedDelivery, $remaining);

            return $this->load($lockedDelivery);
        }, 3);
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
