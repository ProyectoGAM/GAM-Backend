<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ChangeVehicleStatusAction
{
    public function __construct(private AuditRecorder $audit) {}

    public function execute(Vehicle $vehicle, string $status, string $reason, User $actor): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $status, $reason, $actor): Vehicle {
            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            if ($status === 'retired' && $locked->usages()->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['status' => 'Cerrá el uso activo antes de retirar el vehículo.']);
            }
            $before = ['status' => $locked->status, 'reason' => $locked->status_reason];
            $locked->forceFill(['status' => $status, 'status_reason' => $reason, 'version' => $locked->version + 1])->save();
            if ($before['status'] !== $status || $before['reason'] !== $reason) {
                $locked->statusChanges()->create([
                    'actor_id' => $actor->getKey(),
                    'from_status' => $before['status'],
                    'to_status' => $status,
                    'reason' => $reason,
                    'occurred_at' => now(),
                ]);
            }
            $this->audit->record(AuditEntryData::forSubject($locked, $actor, 'vehicles', 'vehicle_status_changed', 'Estado del vehículo actualizado', ['subject_snapshot' => $locked->snapshot(), 'reason' => $reason], ['old' => $before, 'new' => ['status' => $status, 'reason' => $reason]], source: 'api'));

            return $locked;
        });
    }
}
