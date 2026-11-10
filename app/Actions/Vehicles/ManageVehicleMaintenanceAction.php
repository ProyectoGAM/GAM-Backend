<?php

namespace App\Actions\Vehicles;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleMaintenance;
use App\Models\Vehicles\VehicleMaintenancePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManageVehicleMaintenanceAction
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(Vehicle $vehicle, array $data, User $actor): VehicleMaintenance
    {
        $requestData = $data;
        unset($requestData['idempotency_key']);
        $requestHash = hash('sha256', json_encode($requestData, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($vehicle, $data, $actor, $requestHash): VehicleMaintenance {
            $existing = VehicleMaintenance::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->vehicle_id !== $vehicle->getKey() || $existing->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'La clave ya fue usada para otro vehículo.']);
                }

                return $existing->load('items');
            }
            $maintenance = VehicleMaintenance::query()->create([
                'vehicle_id' => $vehicle->getKey(), 'created_by' => $actor->getKey(), 'type' => $data['type'],
                'status' => 'planned', 'immobilizes_vehicle' => $data['immobilizes_vehicle'] ?? false,
                'workshop' => $data['workshop'] ?? null, 'description' => $data['description'], 'notes' => $data['notes'] ?? null,
                'planned_at' => isset($data['planned_at']) ? CarbonImmutable::parse($data['planned_at'], VehicleMaintenance::TIMEZONE) : null,
                'odometer_km' => $data['odometer_km'] ?? null,
                'cost_amount' => $data['cost_amount'] ?? '0.000',
                'cost_currency' => 'UYU', 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $requestHash,
            ]);
            foreach ($data['items'] ?? [] as $item) {
                $plan = isset($item['plan_id']) ? VehicleMaintenancePlan::query()->where('vehicle_id', $vehicle->getKey())->where('public_id', $item['plan_id'])->firstOrFail() : null;
                $maintenance->items()->create(['plan_id' => $plan?->getKey(), 'task' => $item['task'] ?? $plan?->task ?? $maintenance->description, 'completed' => false, 'notes' => $item['notes'] ?? null]);
            }
            $this->audit->record(AuditEntryData::forSubject($maintenance, $actor, 'vehicles', 'vehicle_maintenance_created', 'Intervención de mantenimiento planificada', ['vehicle_id' => $vehicle->public_id, 'type' => $maintenance->type, 'description' => $maintenance->description], source: 'api'));

            return $maintenance->load('items');
        });
    }

    /** @param array<string, mixed> $data */
    public function transition(VehicleMaintenance $maintenance, string $transition, array $data, User $actor): VehicleMaintenance
    {
        return DB::transaction(function () use ($maintenance, $transition, $data, $actor): VehicleMaintenance {
            $record = VehicleMaintenance::query()->whereKey($maintenance->getKey())->lockForUpdate()->with('items.plan')->firstOrFail();
            if (isset($data['version']) && (int) $data['version'] !== $record->version) {
                throw ValidationException::withMessages(['version' => 'La intervención cambió. Actualizá antes de continuar.']);
            }
            if ($transition === 'start') {
                if ($record->status !== 'planned') {
                    throw ValidationException::withMessages(['status' => 'Solo se puede iniciar una intervención planificada.']);
                }
                if ($record->immobilizes_vehicle && $record->vehicle->usages()->whereNull('ended_at')->exists()) {
                    throw ValidationException::withMessages(['status' => 'Cerrá el uso activo del vehículo antes de iniciar un mantenimiento que lo inmoviliza.']);
                }
                $record->status = 'in_progress';
                $record->started_at = now();
            } elseif ($transition === 'complete') {
                if ($record->status !== 'in_progress' && $record->status !== 'planned') {
                    throw ValidationException::withMessages(['status' => 'Esta intervención ya no puede completarse.']);
                }
                $record->status = 'completed';
                $record->completed_at = now();
                $record->completed_by = $actor->getKey();
                $record->odometer_km = $data['odometer_km'] ?? $record->odometer_km;
                $record->cost_amount = $data['cost_amount'] ?? $record->cost_amount;
                foreach ($data['completed_item_ids'] ?? [] as $itemId) {
                    $item = $record->items->firstWhere('public_id', $itemId);
                    if ($item === null) {
                        throw ValidationException::withMessages(['completed_item_ids' => 'Seleccioná tareas de esta intervención.']);
                    }
                    $item->forceFill(['completed' => true])->save();
                    $plan = $item->plan;
                    if ($plan !== null && $plan->active) {
                        $odometerEpoch = $record->vehicle->readings()->effective()->where('meter_type', 'odometer')->orderByDesc('recorded_at')->orderByDesc('id')->value('counter_epoch') ?? 0;
                        $nextAt = $plan->interval_days ? now()->toDateString() : null;
                        if ($plan->interval_days) {
                            $nextAt = now()->addDays($plan->interval_days)->toDateString();
                        }
                        $plan->forceFill([
                            'last_completed_at' => now()->toDateString(), 'last_odometer_km' => $record->odometer_km,
                            'next_due_at' => $nextAt,
                            'next_due_odometer_km' => $plan->interval_km !== null && $record->odometer_km !== null ? (float) $record->odometer_km + (float) $plan->interval_km : null,
                            'next_due_odometer_epoch' => $plan->interval_km !== null && $record->odometer_km !== null ? $odometerEpoch : null,
                            'version' => $plan->version + 1,
                        ])->save();
                    }
                }
            } elseif ($transition === 'cancel') {
                if (! in_array($record->status, ['planned', 'in_progress'], true)) {
                    throw ValidationException::withMessages(['status' => 'Esta intervención ya está finalizada.']);
                }
                $record->status = 'cancelled';
                $record->cancellation_reason = $data['reason'];
            }
            $record->version++;
            $record->save();
            $this->audit->record(AuditEntryData::forSubject($record, $actor, 'vehicles', 'vehicle_maintenance_'.$transition, 'Intervención de mantenimiento '.$this->label($transition), ['vehicle_id' => $record->vehicle->public_id, 'status' => $record->status, 'reason' => $data['reason'] ?? null, 'cost_amount' => (string) $record->cost_amount, 'currency' => 'UYU'], source: 'api'));

            return $record->load('items.plan');
        });
    }

    private function label(string $transition): string
    {
        return ['start' => 'iniciada', 'complete' => 'completada', 'cancel' => 'anulada'][$transition] ?? 'actualizada';
    }
}
