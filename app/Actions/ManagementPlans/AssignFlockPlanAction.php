<?php

namespace App\Actions\ManagementPlans;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\FlockPlanRevision;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateActivity;
use App\Models\ManagementPlans\PlanTemplateVersion;
use App\Models\User;
use App\Services\Lots\LotsHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AssignFlockPlanAction
{
    private const ACTIVITY_FIELDS = [
        'sort_order', 'type', 'title', 'timing_kind', 'start_day', 'end_day',
        'start_week', 'end_week', 'interval_days', 'conditional', 'condition',
        'notes', 'catalog_type', 'catalog_id', 'catalog_snapshot',
    ];

    public function __construct(private LotsHistory $history) {}

    public function assignPublished(Flock $flock, string $templatePublicId, int $version, User $actor, string $operationId, string $source = 'api'): void
    {
        if (FlockPlan::query()->where('flock_id', $flock->id)->exists()) {
            throw new LotsConflict('El lote ya tiene un plan de manejo.');
        }
        $template = PlanTemplate::query()->where('public_id', $templatePublicId)->lockForUpdate()->first();
        if ($template === null || $template->status !== 'active' || $template->published_version !== $version) {
            throw new LotsConflict('La versión seleccionada del plan no está publicada o cambió.');
        }
        $templateVersion = PlanTemplateVersion::query()->where('plan_template_id', $template->id)
            ->where('number', $version)->where('status', 'published')->with('activities')->first();
        if ($templateVersion === null) {
            throw new LotsConflict('La versión seleccionada del plan no está publicada o cambió.');
        }

        $plan = $this->createPlan($flock, $templateVersion->id, null, $flock->entry_date->format('Y-m-d'), $actor, $operationId);
        $revision = $this->createRevision($plan, $actor, $operationId, 'Asignación inicial.');
        foreach ($templateVersion->activities as $activity) {
            $this->copyActivity($revision, $activity, ['source_template_activity_id' => $activity->id]);
        }
        $this->audit($plan, $flock, $actor, $operationId, 'flock_plan_assigned', $source);
    }

    public function inheritFuture(Flock $sourceFlock, Flock $destinationFlock, CarbonImmutable $occurredAt, User $actor, string $operationId, string $source = 'api'): void
    {
        $sourcePlan = FlockPlan::query()->where('flock_id', $sourceFlock->id)->lockForUpdate()->first();
        if ($sourcePlan === null) {
            throw new LotsConflict('Asigna un plan al lote de origen antes de fraccionarlo.');
        }
        $current = FlockPlanRevision::query()->where('flock_plan_id', $sourcePlan->id)
            ->where('number', $sourcePlan->current_revision)->with('activities')->firstOrFail();
        $baseline = $sourcePlan->baseline_date->format('Y-m-d');
        $dayDifference = CarbonImmutable::parse($baseline, config('lots.timezone'))
            ->diffInDays($occurredAt->setTimezone(config('lots.timezone'))->startOfDay(), false);
        $currentDay = max(1, (int) $dayDifference + 1);
        $currentWeek = intdiv($currentDay - 1, 7) + 1;
        $executedActivities = $this->executedActivities($current->activities->all(), $occurredAt);

        $plan = $this->createPlan($destinationFlock, $sourcePlan->plan_template_version_id, $sourceFlock->id, $baseline, $actor, $operationId);
        $revision = $this->createRevision($plan, $actor, $operationId, 'Copia de actividades futuras al fraccionar el lote.');
        $sortOrder = 0;
        foreach ($current->activities as $activity) {
            if (! $this->isFuture($activity, $currentDay, $currentWeek)
                || ($activity->timing_kind !== 'day_recurrence' && isset($executedActivities[$activity->id]))) {
                continue;
            }
            $overrides = [
                'source_template_activity_id' => $activity->source_template_activity_id,
                'copied_from_activity_id' => $activity->id,
                'sort_order' => ++$sortOrder,
            ];
            if ($activity->timing_kind === 'day_recurrence' && $activity->start_day !== null && $activity->start_day < $currentDay) {
                $overrides['start_day'] = $this->nextRecurrenceDay($activity, $currentDay);
            }
            $this->copyActivity($revision, $activity, $overrides);
        }
        $this->audit($plan, $destinationFlock, $actor, $operationId, 'flock_plan_inherited', $source);
    }

    private function createPlan(Flock $flock, ?int $templateVersionId, ?int $sourceFlockId, string $baseline, User $actor, string $operationId): FlockPlan
    {
        $plan = new FlockPlan;
        $plan->forceFill([
            'public_id' => (string) Str::ulid(), 'flock_id' => $flock->id,
            'plan_template_version_id' => $templateVersionId, 'source_flock_id' => $sourceFlockId,
            'baseline_date' => $baseline, 'current_revision' => 1,
            'created_by' => $actor->id, 'operation_id' => $operationId,
        ])->save();

        return $plan;
    }

    private function createRevision(FlockPlan $plan, User $actor, string $operationId, string $reason): FlockPlanRevision
    {
        $revision = new FlockPlanRevision;
        $revision->forceFill([
            'public_id' => (string) Str::ulid(), 'flock_plan_id' => $plan->id,
            'number' => 1, 'operation_id' => $operationId,
            'reason' => $reason, 'created_by' => $actor->id,
        ])->save();

        return $revision;
    }

    /** @param array<string, mixed> $overrides */
    private function copyActivity(FlockPlanRevision $revision, PlanTemplateActivity|FlockPlanActivity $activity, array $overrides): void
    {
        $copy = new FlockPlanActivity;
        $copy->forceFill([
            ...Arr::only($activity->toArray(), self::ACTIVITY_FIELDS), ...$overrides,
            'public_id' => (string) Str::ulid(), 'flock_plan_revision_id' => $revision->id,
        ])->save();
    }

    private function isFuture(FlockPlanActivity $activity, int $currentDay, int $currentWeek): bool
    {
        if ($activity->timing_kind === 'day_recurrence') {
            $endDay = $activity->end_day ?? ($activity->end_week === null ? null : $activity->end_week * 7);

            return $endDay === null || $this->nextRecurrenceDay($activity, $currentDay) <= $endDay;
        }

        return match ($activity->timing_kind) {
            'day' => $activity->start_day >= $currentDay,
            'week' => $activity->start_week >= $currentWeek,
            'week_range' => $activity->end_week === null || $activity->end_week >= $currentWeek,
            'unscheduled' => true,
            default => false,
        };
    }

    private function nextRecurrenceDay(FlockPlanActivity $activity, int $currentDay): int
    {
        $startDay = (int) $activity->start_day;
        if ($startDay >= $currentDay) {
            return $startDay;
        }
        $interval = max(1, (int) $activity->interval_days);

        return $startDay + (int) ceil(($currentDay - $startDay) / $interval) * $interval;
    }

    /**
     * @param  list<FlockPlanActivity>  $activities
     * @return array<int, true>
     */
    private function executedActivities(array $activities, CarbonImmutable $occurredAt): array
    {
        $ids = array_map(fn (FlockPlanActivity $activity): int => $activity->id, $activities);
        $publicIds = array_map(fn (FlockPlanActivity $activity): string => $activity->public_id, $activities);
        if ($ids === []) {
            return [];
        }
        $executed = [];

        foreach (['flock_movements', 'weighings', 'egg_collections', 'mortality_records'] as $table) {
            foreach (DB::table($table)->whereIn('flock_plan_activity_id', $ids)
                ->where('occurred_at', '<=', $occurredAt->toIso8601String())
                ->pluck('flock_plan_activity_id') as $activityId) {
                $executed[(int) $activityId] = true;
            }
        }

        $publicToInternal = array_combine($publicIds, $ids);
        foreach (['vaccination_applications', 'medicine_applications', 'ration_changes', 'manual_practices'] as $table) {
            foreach (DB::table($table)->whereIn('plan_activity_id', $publicIds)
                ->where('occurred_at', '<=', $occurredAt->toIso8601String())
                ->pluck('plan_activity_id') as $publicId) {
                $executed[$publicToInternal[(string) $publicId]] = true;
            }
        }

        return $executed;
    }

    private function audit(FlockPlan $plan, Flock $flock, User $actor, string $operationId, string $event, string $source): void
    {
        $snapshot = [
            'id' => $plan->public_id, 'flock_id' => $flock->public_id,
            'baseline_date' => $plan->baseline_date->format('Y-m-d'), 'revision' => 1,
            'activity_count' => FlockPlanActivity::query()->whereHas('revision', fn ($query) => $query->where('flock_plan_id', $plan->id))->count(),
        ];
        $this->history->audit($plan, $actor, $event, 'Plan de manejo asignado', $operationId, [], $snapshot, $flock->production_unit_id, $source);
    }
}
