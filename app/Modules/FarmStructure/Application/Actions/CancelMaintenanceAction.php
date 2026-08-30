<?php

namespace App\Modules\FarmStructure\Application\Actions;

use App\Models\FarmStructure\Maintenance;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Application\Data\MaintenanceSnapshot;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;
use App\Modules\FarmStructure\Domain\Exceptions\MaintenanceConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CancelMaintenanceAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(Maintenance $maintenance, int $version, string $reason, User $actor): Maintenance
    {
        Gate::forUser($actor)->authorize('cancel', $maintenance);

        return DB::transaction(function () use ($maintenance, $version, $reason, $actor): Maintenance {
            $locked = Maintenance::query()->whereKey($maintenance->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(MaintenanceStatus::Cancelled)) {
                throw new MaintenanceConflict('El mantenimiento ya está cancelado.');
            }

            if ($locked->version !== $version) {
                throw new MaintenanceConflict('El mantenimiento fue modificado. Actualiza los datos e inténtalo nuevamente.');
            }

            $before = MaintenanceSnapshot::from($locked);
            $locked->status = MaintenanceStatus::Cancelled;
            $locked->cancellation_reason = $reason;
            $locked->cancelled_at = now();
            $locked->version++;
            $locked->save();
            $after = MaintenanceSnapshot::from($locked);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'farm_structure',
                event: 'maintenance_cancelled',
                description: 'Mantenimiento cancelado',
                properties: ['subject_snapshot' => $after, 'reason' => $reason, 'result' => 'cancelled'],
                attributeChanges: ['old' => $before, 'new' => $after],
                upId: $locked->poultryHouse->production_unit_id,
                source: 'api',
            ));

            return $locked;
        });
    }
}
