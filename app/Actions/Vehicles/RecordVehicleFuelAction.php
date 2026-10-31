<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleFuelEntry;
use App\Models\Vehicles\VehicleUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RecordVehicleFuelAction
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(Vehicle $vehicle, array $data, User $actor): VehicleFuelEntry
    {
        return DB::transaction(function () use ($vehicle, $data, $actor): VehicleFuelEntry {
            $existing = VehicleFuelEntry::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                $usageMatches = isset($data['usage_id'])
                    ? $existing->usage?->public_id === $data['usage_id']
                    : $existing->usage_id === null;
                $occurredAtMatches = ! isset($data['occurred_at'])
                    || $existing->occurred_at?->equalTo(Carbon::parse($data['occurred_at'])) === true;
                if ($existing->vehicle_id !== $vehicle->getKey()
                    || ($existing->created_by !== $actor->getKey() && ! $actor->can('vehicles.manage'))
                    || $this->normalizeDecimal($existing->liters) !== $this->normalizeDecimal($data['liters'])
                    || $this->normalizeDecimal($existing->amount) !== $this->normalizeDecimal($data['amount'])
                    || $existing->fuel_type !== $data['fuel_type']
                    || $existing->supplier !== ($data['supplier'] ?? null)
                    || $this->normalizeDecimal($existing->odometer_km) !== $this->normalizeDecimal($data['odometer_km'] ?? null)
                    || $existing->receipt_sha256 !== ($data['receipt_sha256'] ?? null)
                    || $existing->receipt_original_name !== ($data['receipt_original_name'] ?? null)
                    || $existing->notes !== ($data['notes'] ?? null)
                    || ! $usageMatches || ! $occurredAtMatches) {
                    throw ValidationException::withMessages(['idempotency_key' => 'La clave ya fue usada con otros datos.']);
                }

                return $existing;
            }
            if (isset($data['usage_id'])) {
                $usage = VehicleUsage::query()->where('vehicle_id', $vehicle->getKey())->whereNull('ended_at')->where('public_id', $data['usage_id'])->first();
                if ($usage === null) {
                    throw ValidationException::withMessages(['usage_id' => 'El uso indicado no está abierto para este vehículo.']);
                }
                if ($usage->driver_id !== $actor->getKey() && ! $actor->can('vehicles.manage')) {
                    throw ValidationException::withMessages(['usage_id' => 'Solo podés registrar combustible en tu propio uso activo.']);
                }
                $data['usage_id'] = $usage->getKey();
            } elseif ($actor->hasRole('delivery') && ! $actor->can('vehicles.manage')) {
                throw ValidationException::withMessages(['usage_id' => 'El registro móvil debe asociarse a tu reparto activo.']);
            }
            $data['occurred_at'] ??= now();
            $entry = VehicleFuelEntry::query()->create([...$data, 'vehicle_id' => $vehicle->getKey(), 'created_by' => $actor->getKey(), 'currency' => 'UYU']);
            $this->audit->record(AuditEntryData::forSubject($entry, $actor, 'vehicles', 'vehicle_fuel_recorded', 'Carga de combustible registrada', ['vehicle_id' => $vehicle->public_id, 'liters' => (string) $entry->liters, 'amount' => (string) $entry->amount, 'currency' => $entry->currency, 'occurred_at' => $entry->occurred_at?->toIso8601String(), 'supplier' => $entry->supplier], source: 'api'));

            return $entry;
        });
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
