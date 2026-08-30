<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\Maintenance;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Application\Data\MaintenanceSnapshot;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;
use App\Modules\FarmStructure\Domain\Exceptions\MaintenanceConflict;
use App\Shared\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class UpdateMaintenanceAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{maintenance_date?: string, description?: string, cost_amount?: string, cost_currency?: string, responsible_user_id?: int} $attributes */
    public function execute(Maintenance $maintenance, array $attributes, int $version, string $reason, User $actor): Maintenance
    {
        Gate::forUser($actor)->authorize('update', $maintenance);

        return DB::transaction(function () use ($maintenance, $attributes, $version, $reason, $actor): Maintenance {
            $locked = Maintenance::query()->whereKey($maintenance->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MaintenanceStatus::Completed) {
                throw new MaintenanceConflict('No se puede corregir un mantenimiento cancelado.');
            }

            if ($locked->version !== $version) {
                throw new MaintenanceConflict('El mantenimiento fue modificado. Actualiza los datos e inténtalo nuevamente.');
            }

            $before = MaintenanceSnapshot::from($locked);
            $cost = Money::fromDecimal($attributes['cost_amount'] ?? $locked->cost_amount, $attributes['cost_currency'] ?? $locked->cost_currency);

            if ($cost->isNegative()) {
                throw new MaintenanceConflict('El costo del mantenimiento no puede ser negativo.');
            }

            if (isset($attributes['responsible_user_id']) && (int) $attributes['responsible_user_id'] !== $locked->responsible_user_id) {
                $responsible = User::query()->whereKey($attributes['responsible_user_id'])->lockForUpdate()->first();

                if ($responsible === null) {
                    throw new MaintenanceConflict('El responsable debe ser un usuario activo.');
                }

                $locked->responsible_user_id = $responsible->id;
                $locked->responsible_name = $responsible->name;
            }

            $locked->maintenance_date = $attributes['maintenance_date'] ?? $locked->maintenance_date;
            $locked->description = $attributes['description'] ?? $locked->description;
            $locked->cost_amount = $cost->amount();
            $locked->cost_currency = $cost->currency();
            $locked->version++;
            $locked->save();
            $after = MaintenanceSnapshot::from($locked);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'farm_structure',
                event: 'maintenance_corrected',
                description: 'Mantenimiento corregido',
                properties: ['subject_snapshot' => $after, 'reason' => $reason, 'result' => 'corrected'],
                attributeChanges: ['old' => $before, 'new' => $after],
                upId: $locked->poultryHouse->production_unit_id,
                source: 'api',
            ));

            return $locked;
        });
    }
}
