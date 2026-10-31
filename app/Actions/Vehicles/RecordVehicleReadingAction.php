<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleMeterReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RecordVehicleReadingAction
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(Vehicle $vehicle, array $data, User $actor, ?int $usageId = null, string $source = 'manual'): VehicleMeterReading
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $usageId, $source): VehicleMeterReading {
            Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();
            $existing = VehicleMeterReading::query()->where('idempotency_key', $idempotencyKey)->first();
            $type = 'odometer';
            $eventType = (string) ($data['event_type'] ?? 'reading');
            $recordedAt = Carbon::parse($data['recorded_at'] ?? now());
            $corrects = null;
            if ($eventType === 'correction') {
                $corrects = $vehicle->readings()->where('public_id', $data['corrects_reading_id'])->firstOrFail();
                $priorCorrection = $vehicle->readings()->where('corrects_reading_id', $corrects->getKey())->first();
                if ($corrects->meter_type !== $type || ($priorCorrection !== null && $priorCorrection->getKey() !== $existing?->getKey())) {
                    throw ValidationException::withMessages(['corrects_reading_id' => 'Elegí una lectura vigente del mismo contador.']);
                }
                $recordedAt = $corrects->recorded_at;
            }
            if ($existing !== null) {
                if ($existing->vehicle_id !== $vehicle->getKey()
                    || $this->normalizeDecimal($existing->value) !== $this->normalizeDecimal($data['value'])
                    || $existing->meter_type !== $type
                    || $existing->event_type !== $eventType
                    || $existing->correctsReading?->public_id !== $corrects?->public_id
                    || $existing->usage_id !== $usageId
                    || $existing->source !== $source
                    || $existing->notes !== ($data['notes'] ?? null)
                    || ! $existing->recorded_at?->equalTo($recordedAt)
                    || $existing->event_reason !== ($eventType === 'correction' ? $data['correction_reason'] : ($data['event_reason'] ?? null))) {
                    throw ValidationException::withMessages(['idempotency_key' => 'La clave ya fue usada con otra lectura.']);
                }

                return $existing;
            }

            $latest = $vehicle->readings()->effective()->where('meter_type', $type)->orderByDesc('recorded_at')->orderByDesc('id')->first();
            if ($eventType === 'reset') {
                if ($latest !== null && $recordedAt->lt($latest->recorded_at)) {
                    throw ValidationException::withMessages(['recorded_at' => 'El reinicio de tablero debe registrarse después de la última lectura vigente.']);
                }
                $counterEpoch = ($latest?->counter_epoch ?? -1) + 1;
            } elseif ($corrects !== null) {
                $counterEpoch = $corrects->counter_epoch;
            } else {
                $preceding = $vehicle->readings()->effective()->where('meter_type', $type)->where('recorded_at', '<=', $recordedAt)->orderByDesc('recorded_at')->orderByDesc('id')->first();
                $counterEpoch = $preceding?->counter_epoch ?? 0;
            }

            $value = (float) $data['value'];
            if ($eventType !== 'reset') {
                $neighbors = $vehicle->readings()->effective()->where('meter_type', $type)->where('counter_epoch', $counterEpoch);
                if ($corrects !== null) {
                    $neighbors->where('vehicle_meter_readings.id', '!=', $corrects->getKey());
                }
                $older = (clone $neighbors)->where('recorded_at', '<', $recordedAt)->orderByDesc('recorded_at')->orderByDesc('id')->first();
                $newer = (clone $neighbors)->where('recorded_at', '>', $recordedAt)->orderBy('recorded_at')->orderBy('id')->first();
                if (($older !== null && $value < (float) $older->value) || ($newer !== null && $value > (float) $newer->value)) {
                    throw ValidationException::withMessages(['value' => 'La lectura no respeta el orden del contador. Para corregirla, registrá una rectificación con motivo.']);
                }
            }

            $reason = $eventType === 'correction' ? $data['correction_reason'] : ($data['event_reason'] ?? null);
            $reading = $vehicle->readings()->create([
                'usage_id' => $usageId,
                'created_by' => $actor->getKey(),
                'corrects_reading_id' => $corrects?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'meter_type' => $type,
                'event_type' => $eventType,
                'event_reason' => $reason,
                'counter_epoch' => $counterEpoch,
                'value' => $data['value'],
                'recorded_at' => $recordedAt,
                'received_at' => now(),
                'source' => $source,
                'correction_reason' => $eventType === 'correction' ? $reason : null,
                'notes' => $data['notes'] ?? null,
            ]);
            $event = $eventType === 'correction' ? 'vehicle_meter_reading_corrected' : ($eventType === 'reset' ? 'vehicle_meter_reset_recorded' : 'vehicle_meter_reading_recorded');
            $description = $eventType === 'correction' ? 'Lectura de tablero corregida' : ($eventType === 'reset' ? 'Reinicio de tablero registrado' : 'Lectura de tablero registrada');
            $this->audit->record(AuditEntryData::forSubject($reading, $actor, 'vehicles', $event, $description, [
                'vehicle_id' => $vehicle->public_id,
                'meter_type' => $type,
                'event_type' => $eventType,
                'counter_epoch' => $counterEpoch,
                'value' => (string) $reading->value,
                'recorded_at' => $reading->recorded_at?->toIso8601String(),
                'received_at' => $reading->received_at?->toIso8601String(),
                'corrects_reading_id' => $corrects?->public_id,
                'reason' => $reason,
            ], source: 'api'));

            return $reading;
        });
    }

    private function normalizeDecimal(mixed $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
