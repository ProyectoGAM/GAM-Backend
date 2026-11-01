<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveVehicleAction
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor, ?Vehicle $vehicle = null): Vehicle
    {
        return DB::transaction(function () use ($data, $actor, $vehicle): Vehicle {
            $locked = $vehicle === null ? new Vehicle : Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $plate = strtoupper(trim((string) $data['plate']));
            $normalized = preg_replace('/[^A-Z0-9]/', '', $plate) ?? '';
            if (Vehicle::query()->where('plate_normalized', $normalized)->when($locked->exists, fn ($query) => $query->where('id', '!=', $locked->getKey()))->exists()) {
                throw ValidationException::withMessages(['plate' => 'Ya existe un vehículo con esa matrícula.']);
            }
            if (isset($data['version']) && $vehicle !== null && (int) $data['version'] !== $locked->version) {
                throw ValidationException::withMessages(['version' => 'El vehículo cambió desde que abriste el formulario. Actualizá la ficha.']);
            }
            if (isset($data['habitual_driver_id'])) {
                $driver = User::query()->whereKey($data['habitual_driver_id'])->first();
                if ($driver === null || ! $driver->hasRole('delivery')) {
                    throw ValidationException::withMessages(['habitual_driver_id' => 'Elegí una cuenta activa con rol de repartidor.']);
                }
            }
            $before = $locked->exists ? $locked->snapshot() : [];
            $locked->fill([...$data, 'plate' => $plate, 'plate_normalized' => $normalized]);
            $locked->version = $locked->exists ? $locked->version + 1 : 1;
            $locked->save();
            $locked->refresh();
            $after = $locked->snapshot();
            if ($vehicle === null) {
                $locked->statusChanges()->create([
                    'actor_id' => $actor->getKey(),
                    'from_status' => null,
                    'to_status' => $locked->status,
                    'reason' => 'Alta inicial del vehiculo.',
                    'occurred_at' => now(),
                ]);
            }
            $this->audit->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'vehicles',
                event: $vehicle === null ? 'vehicle_created' : 'vehicle_updated',
                description: $vehicle === null ? 'Vehículo creado' : 'Ficha del vehículo actualizada',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                source: 'api',
            ));

            return $locked->load('habitualDriver:id,name');
        });
    }
}
