<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\VehicleUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CloseVehicleUsageAction
{
    public function __construct(private AuditRecorder $audit, private RecordVehicleReadingAction $readings) {}

    /** @param array<string, mixed> $data */
    public function execute(VehicleUsage $usage, array $data, User $actor): VehicleUsage
    {
        return DB::transaction(function () use ($usage, $data, $actor): VehicleUsage {
            $locked = VehicleUsage::query()->whereKey($usage->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->ended_at !== null) {
                return $locked;
            }
            if (isset($data['odometer_km']) && $locked->start_odometer_km !== null && (float) $data['odometer_km'] < (float) $locked->start_odometer_km) {
                throw ValidationException::withMessages(['odometer_km' => 'El kilometraje de cierre no puede ser menor que el de salida.']);
            }
            $locked->forceFill(['ended_at' => now(), 'active_vehicle_key' => null, 'end_odometer_km' => $data['odometer_km'] ?? null, 'notes' => trim(($locked->notes ?? '').' '.($data['notes'] ?? ''))])->save();
            if (isset($data['odometer_km'])) {
                $this->readings->execute($locked->vehicle, ['meter_type' => 'odometer', 'value' => $data['odometer_km'], 'recorded_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'notes' => 'Lectura al cierre del uso'], $actor, $locked->getKey(), 'usage_end');
            }
            $this->audit->record(AuditEntryData::forSubject($locked, $actor, 'vehicles', 'vehicle_usage_closed', 'Uso del vehículo cerrado', ['vehicle_id' => $locked->vehicle->public_id, 'ended_at' => $locked->ended_at?->toIso8601String(), 'odometer_km' => $locked->end_odometer_km], source: 'api'));

            return $locked->refresh();
        });
    }
}
