<?php

namespace App\Actions\Inventory;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Lots\FlockStatus;
use App\Enums\Notifications\AlertTimingType;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FlockFeedingChange;
use App\Services\Inventory\FlockFeedingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateManualFeedingReviewAlertsAction
{
    public function __construct(private SynchronizeOperationalAlertsAction $synchronize, private Clock $clock) {}

    public function execute(int $unitId): void
    {
        DB::transaction(function () use ($unitId): void {
            $type = AlertTimingType::ManualFeedingReviewDue;
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$type->value.'-alerts:'.$unitId]);
            $unit = ProductionUnit::query()->findOrFail($unitId);
            $evaluatedAt = $this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE);
            $pending = $unit->status === ProductionUnitStatus::Active ? $this->pending($unit, $evaluatedAt) : [];
            $this->synchronize->execute($unitId, OperationalAlertType::ManualFeedingReviewDue, 'can_view_flock_feeding', 'flock', $evaluatedAt, $pending);
        }, 3);
    }

    /** @return array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}> */
    private function pending(ProductionUnit $unit, CarbonImmutable $evaluatedAt): array
    {
        $today = $evaluatedAt->startOfDay();
        $thresholds = AlertTimingType::ManualFeedingReviewDue->thresholds($unit);
        $latest = FlockFeedingChange::query()->join('flocks', 'flocks.id', '=', 'flock_feeding_changes.flock_id')
            ->where('flocks.production_unit_id', $unit->id)->where('flocks.status', '!=', FlockStatus::Finished)
            ->where('flocks.established_at', '<=', $evaluatedAt->toIso8601String())
            ->where('effective_date', '<=', $today->toDateString())
            ->selectRaw('DISTINCT ON (flock_id) flock_feeding_changes.id, mode, effective_date, manual_review_since, flocks.public_id, flocks.code')
            ->orderBy('flock_id')->orderByDesc('effective_date')->orderByDesc('flock_feeding_changes.id');

        $manual = DB::query()->fromSub($latest, 'configurations')->where('mode', 'manual');
        $pending = [];
        foreach ($manual->lazyById(100) as $configuration) {
            $since = CarbonImmutable::parse($configuration->manual_review_since ?? $configuration->effective_date, FlockFeedingResolver::TIMEZONE);
            $days = (int) $since->diffInDays($today, false);
            $urgency = $thresholds->urgency($days);
            if ($urgency === null) {
                continue;
            }

            $flockId = (string) $configuration->public_id;
            $pending['manual-feeding:'.$flockId.':'.$since->toDateString()] = [
                'entity_id' => $flockId,
                'target_path' => '/administracion/lotes/lotes/'.$flockId.'/alimentacion',
                'urgency' => $urgency,
                'title' => 'Revisión de ración manual pendiente',
                'message' => "Revisá la cantidad diaria del lote «{$configuration->code}». Pasaron {$days} días desde el último cambio o confirmación.",
            ];
        }

        return $pending;
    }
}
