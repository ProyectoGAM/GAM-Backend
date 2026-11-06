<?php

namespace App\Actions\Notifications;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Notifications\AlertTimingType;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Lots\LotsAuthorization;
use App\ValueObjects\Notifications\AlertTimingThresholds;
use Illuminate\Support\Facades\DB;

final readonly class SetAlertTimingThresholdsAction
{
    public function __construct(private AuditRecorder $auditRecorder, private ProductionUnitAccess $unitAccess) {}

    public function execute(int $unitId, AlertTimingType $type, AlertTimingThresholds $thresholds, User $actor): ProductionUnit
    {
        return DB::transaction(function () use ($unitId, $type, $thresholds, $actor): ProductionUnit {
            $currentActor = User::query()->findOrFail($actor->id);
            LotsAuthorization::ensure($currentActor, 'can_manage_alert_thresholds');
            $this->unitAccess->assertUnit($unitId, $currentActor);
            $unit = ProductionUnit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();
            $old = $type->thresholds($unit)->toArray();
            $new = $thresholds->toArray();
            if ($old === $new) {
                return $unit;
            }

            $settings = $unit->alert_timing_thresholds ?? [];
            $settings[$type->value] = $new;
            $unit->forceFill(['alert_timing_thresholds' => $settings])->save();
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $unit,
                actor: $currentActor,
                logName: 'notifications',
                event: 'alert_timing_thresholds_changed',
                description: 'Umbrales de alerta configurados',
                properties: ['result' => 'success', 'alert_type' => $type->value, 'subject_snapshot' => ['id' => $unitId, 'name' => $unit->name]],
                attributeChanges: ['old' => $old, 'new' => $new],
                upId: $unitId,
                source: 'api',
            ));

            return $unit;
        }, 3);
    }
}
