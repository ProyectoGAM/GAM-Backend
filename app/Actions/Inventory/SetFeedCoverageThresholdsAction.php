<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Support\Facades\DB;

final readonly class SetFeedCoverageThresholdsAction
{
    public function __construct(private AuditRecorder $auditRecorder, private ProductionUnitAccess $unitAccess) {}

    public function execute(int $unitId, int $warningDays, int $urgentDays, User $actor): ProductionUnit
    {
        return DB::transaction(function () use ($unitId, $warningDays, $urgentDays, $actor): ProductionUnit {
            $actor = $actor->fresh();
            LotsAuthorization::ensure($actor, 'can_manage_feed_minimums');
            $this->unitAccess->assertUnit($unitId, $actor);

            $unit = ProductionUnit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();
            $new = ['feed_coverage_warning_days' => $warningDays, 'feed_coverage_urgent_days' => $urgentDays];
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
