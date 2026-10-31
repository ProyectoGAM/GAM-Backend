<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleUsage;
use App\Services\Vehicles\VehicleAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StartVehicleUsageAction
{
    public function __construct(private AuditRecorder $audit, private VehicleAvailability $availability, private RecordVehicleReadingAction $readings) {}

    /** @param array<string, mixed> $data */
    public function execute(Vehicle $vehicle, User $driver, array $data, string $key, ?int $deliveryId = null, ?User $actor = null): VehicleUsage
    {
        return DB::transaction(function () use ($vehicle, $driver, $data, $key, $deliveryId, $actor): VehicleUsage {
            $existing = VehicleUsage::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! $this->matchesRequest($existing, $vehicle, $driver, $data, $deliveryId)) {
                    throw ValidationException::withMessages(['vehicle_id' => 'La clave de inicio ya fue usada con otros datos.']);
                }

                return $existing;
            }
            $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $existing = VehicleUsage::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! $this->matchesRequest($existing, $locked, $driver, $data, $deliveryId)) {
                    throw ValidationException::withMessages(['vehicle_id' => 'La clave de inicio ya fue usada con otros datos.']);
                }

                return $existing;
            }
            $availability = $this->availability->describe($locked);
            if ($availability['availability'] !== 'available') {
                throw ValidationException::withMessages(['vehicle_id' => $availability['reason'] ?? 'El vehículo no está disponible.']);
            }
            $usage = VehicleUsage::query()->create([
                'vehicle_id' => $locked->getKey(), 'driver_id' => $driver->getKey(), 'delivery_id' => $deliveryId,
                'active_vehicle_key' => (string) $locked->getKey(), 'purpose' => $data['purpose'] ?? 'delivery',
                'notes' => $data['notes'] ?? null, 'started_at' => now(), 'start_odometer_km' => $data['odometer_km'] ?? null,
                'vehicle_snapshot' => $locked->snapshot(),
                'driver_snapshot' => $driver->name, 'idempotency_key' => $key,
            ]);
            $readingActor = $actor ?? $driver;
            if (isset($data['odometer_km'])) {
                $this->readings->execute($locked, ['meter_type' => 'odometer', 'value' => $data['odometer_km'], 'recorded_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'notes' => 'Lectura al inicio del uso'], $readingActor, $usage->getKey(), 'usage_start');
            }
            $this->audit->record(AuditEntryData::forSubject($usage, $actor ?? $driver, 'vehicles', 'vehicle_usage_started', 'Uso del vehículo iniciado', ['vehicle_snapshot' => $usage->vehicle_snapshot, 'purpose' => $usage->purpose, 'delivery_id' => $deliveryId, 'driver_id' => $driver->getKey()], operationId: $key, source: 'api'));

            return $usage;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function matchesRequest(VehicleUsage $usage, Vehicle $vehicle, User $driver, array $data, ?int $deliveryId): bool
    {
        return $usage->vehicle_id === $vehicle->getKey()
            && $usage->driver_id === $driver->getKey()
            && $usage->delivery_id === $deliveryId
            && $usage->purpose === ($data['purpose'] ?? 'delivery')
            && $usage->notes === ($data['notes'] ?? null)
            && $this->normalizeDecimal($usage->start_odometer_km) === $this->normalizeDecimal($data['odometer_km'] ?? null);
    }

    private function normalizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
