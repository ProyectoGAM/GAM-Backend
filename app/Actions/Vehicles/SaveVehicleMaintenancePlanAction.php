<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleMaintenancePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveVehicleMaintenancePlanAction
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(Vehicle $vehicle, array $data, User $actor, ?VehicleMaintenancePlan $plan = null): VehicleMaintenancePlan
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $plan): VehicleMaintenancePlan {
            if (isset($data['version']) && $plan !== null && (int) $data['version'] !== $plan->version) {
                throw ValidationException::withMessages(['version' => 'El plan cambió. Actualizá la información antes de guardar.']);
            }
            $record = $plan === null ? new VehicleMaintenancePlan : VehicleMaintenancePlan::query()->where('vehicle_id', $vehicle->getKey())->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $data['next_due_odometer_epoch'] = ($data['next_due_odometer_km'] ?? null) !== null
                ? ($vehicle->readings()->effective()->where('meter_type', 'odometer')->orderByDesc('recorded_at')->orderByDesc('id')->value('counter_epoch') ?? 0)
                : null;
            $record->fill($data);
            $record->vehicle_id = $vehicle->getKey();
            $record->version = $record->exists ? $record->version + 1 : 1;
            $record->save();
            $this->audit->record(AuditEntryData::forSubject($record, $actor, 'vehicles', $plan === null ? 'vehicle_maintenance_plan_created' : 'vehicle_maintenance_plan_updated', $plan === null ? 'Plan preventivo creado' : 'Plan preventivo actualizado', ['vehicle_id' => $vehicle->public_id, 'task' => $record->task], source: 'api'));

            return $record;
        });
    }
}
