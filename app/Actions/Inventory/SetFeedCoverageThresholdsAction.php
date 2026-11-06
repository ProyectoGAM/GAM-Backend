<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SetFeedCoverageThresholdsAction
{
    public function __construct(private AuditRecorder $auditRecorder, private ProductionUnitAccess $unitAccess) {}

    public function execute(int $unitId, int $warningDays, int $urgentDays, User $actor, ?int $lowDays = null, bool $replaceLowDays = false): ProductionUnit
    {
        return DB::transaction(function () use ($unitId, $warningDays, $urgentDays, $actor, $lowDays, $replaceLowDays): ProductionUnit {
            $actor = User::query()->findOrFail($actor->id);
            $permission = LotsAuthorization::allows($actor, 'can_manage_alert_thresholds') ? 'can_manage_alert_thresholds' : 'can_manage_feed_minimums';
            LotsAuthorization::ensure($actor, $permission);
            $this->unitAccess->assertUnit($unitId, $actor);

            $unit = ProductionUnit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();
            $lowDays = $replaceLowDays ? $lowDays : $unit->feed_coverage_low_days;
            if ($lowDays !== null && $lowDays <= $warningDays) {
                throw ValidationException::withMessages(['dias_aviso_bajo' => 'Los días de aviso bajo deben ser mayores que los días de aviso medio.']);
            }
            $new = [
                'feed_coverage_warning_days' => $warningDays,
                'feed_coverage_urgent_days' => $urgentDays,
                'feed_coverage_low_days' => $lowDays,
            ];
            $old = $unit->only(array_keys($new));
            if ($old === $new) {
                return $unit;
            }

            $unit->forceFill($new)->save();
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $unit,
                actor: $actor,
                logName: 'inventory',
                event: 'feed_coverage_thresholds_changed',
                description: 'Umbrales de alerta de cobertura de ración configurados',
                properties: ['result' => 'success', 'subject_snapshot' => ['id' => $unit->id, 'name' => $unit->name, ...$new]],
                attributeChanges: ['old' => $old, 'new' => $new],
                upId: $unitId,
                source: 'api',
            ));

            return $unit;
        }, 3);
    }
}
