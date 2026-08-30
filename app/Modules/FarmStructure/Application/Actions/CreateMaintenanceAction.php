<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Application\Data\MaintenanceSnapshot;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;
use App\Modules\FarmStructure\Domain\Exceptions\MaintenanceConflict;
use App\Shared\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CreateMaintenanceAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{maintenance_date: string, description: string, cost_amount: string, cost_currency: string, responsible_user_id: int, idempotency_key: string} $attributes */
    public function execute(PoultryHouse $poultryHouse, array $attributes, User $actor): Maintenance
    {
        Gate::forUser($actor)->authorize('create', Maintenance::class);

        return DB::transaction(function () use ($poultryHouse, $attributes, $actor): Maintenance {
            $cost = Money::fromDecimal($attributes['cost_amount'], $attributes['cost_currency']);

            if ($cost->isNegative()) {
                throw new MaintenanceConflict('El costo del mantenimiento no puede ser negativo.');
            }

            $requestHash = hash('sha256', json_encode([
                'poultry_house_id' => $poultryHouse->id,
                'maintenance_date' => $attributes['maintenance_date'],
                'description' => $attributes['description'],
                'cost' => $cost->toArray(),
                'responsible_user_id' => (int) $attributes['responsible_user_id'],
            ], JSON_THROW_ON_ERROR));

            /** Serializa reintentos del mismo actor y bloquea responsables en orden estable. */
            $users = User::query()->whereIn('id', [$actor->id, $attributes['responsible_user_id']])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if (! $users->has($actor->id)) {
                throw new MaintenanceConflict('El usuario que registra el mantenimiento ya no está activo.');
            }

            $existing = Maintenance::query()->where('created_by', $actor->id)
                ->where('idempotency_key', $attributes['idempotency_key'])->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new MaintenanceConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $existing;
            }

            $responsible = $users->get($attributes['responsible_user_id']);

            if (! $responsible instanceof User) {
                throw new MaintenanceConflict('El responsable debe ser un usuario activo.');
            }

            $house = PoultryHouse::query()->whereKey($poultryHouse->id)->lockForUpdate()->firstOrFail();
            $maintenance = Maintenance::query()->create([
                'poultry_house_id' => $house->id,
                'maintenance_date' => $attributes['maintenance_date'],
                'description' => $attributes['description'],
                'cost_amount' => $cost->amount(),
                'cost_currency' => $cost->currency(),
                'responsible_user_id' => $responsible->id,
                'responsible_name' => $responsible->name,
                'created_by' => $actor->id,
                'idempotency_key' => $attributes['idempotency_key'],
                'request_hash' => $requestHash,
                'status' => MaintenanceStatus::Completed,
                'version' => 1,
            ]);
            $snapshot = MaintenanceSnapshot::from($maintenance);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $maintenance,
                actor: $actor,
                logName: 'farm_structure',
                event: 'maintenance_created',
                description: 'Mantenimiento registrado',
                properties: ['subject_snapshot' => $snapshot, 'result' => 'completed'],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                upId: $house->production_unit_id,
                source: 'api',
            ));

            return $maintenance;
        });
    }
}
