<?php

namespace App\Services\ManagementPlans;

use App\Enums\Lots\FlockStatus;
use App\Enums\ManagementPlans\HealthActivityType;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\FlockPlanRevision;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\VaccinationApplication;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;

final class ScheduledHealthActivities
{
    /** @return Generator<string, array{entity_id: string, activity: string, flock: string, due: CarbonImmutable}> */
    public function pending(HealthActivityType $type, int $unitId, CarbonImmutable $evaluatedAt, CarbonImmutable $through): Generator
    {
        $plans = FlockPlan::query()
            ->whereHas('flock', fn ($flocks) => $flocks->where('production_unit_id', $unitId)
                ->where('status', '!=', FlockStatus::Finished)->where('established_at', '<=', $evaluatedAt->toIso8601String()))
            ->with(['flock', 'revisions.activities' => fn ($activities) => $activities
                ->where('type', $type->value)->where('conditional', false)->where('timing_kind', '!=', 'unscheduled')]);

        [$applicationModel, $catalogColumn] = match ($type) {
            HealthActivityType::Vaccination => [new VaccinationApplication, 'vaccine_id'],
            HealthActivityType::Medication => [new MedicineApplication, 'medicine_id'],
        };

        foreach ($plans->lazyById(50)->chunk(50) as $batch) {
            $applications = $applicationModel->newQuery()->whereIn('flock_id', $batch->pluck('flock_id'))
                ->whereNotNull('plan_activity_id')->where('occurred_at', '<=', $evaluatedAt->toIso8601String())
                ->whereNotExists(fn ($corrections) => $corrections->selectRaw('1')->from('management_execution_corrections as c')
                    ->where('c.original_type', $type->value)->whereColumn('c.original_public_id', $applicationModel->qualifyColumn('public_id')))
                ->get(['flock_id', $catalogColumn.' as catalog_id', 'plan_activity_id', 'occurred_at'])->groupBy('flock_id');

            foreach ($batch as $plan) {
                yield from $this->pendingForPlan($plan, $applications->get($plan->flock_id, collect()), $through);
            }
        }
    }

    /**
     * @param  Collection<int, VaccinationApplication|MedicineApplication>  $applications
     * @return Generator<string, array{entity_id: string, activity: string, flock: string, due: CarbonImmutable}>
     */
    private function pendingForPlan(FlockPlan $plan, Collection $applications, CarbonImmutable $through): Generator
    {
        $revisions = $plan->revisions->where('number', '<=', $plan->current_revision)->reverse()
            ->mapWithKeys(fn (FlockPlanRevision $revision) => [
                $revision->number => $revision->activities->groupBy($this->fingerprint(...)),
            ]);
        $current = $revisions->get($plan->current_revision);
        if ($current === null) {
            return;
        }

        $baseline = CarbonImmutable::parse($plan->baseline_date->toDateString(), $through->getTimezone());
        $applicationsByActivity = $applications->groupBy('plan_activity_id');

        foreach ($current as $fingerprint => $activities) {
            foreach ($activities as $activity) {
                $ids = [$activity->public_id];
                if ($activities->count() === 1) {
                    foreach ($revisions->except([$plan->current_revision]) as $revision) {
                        $matching = $revision->get($fingerprint);
                        if ($matching?->count() !== 1) {
                            break;
                        }
                        $ids[] = $matching->firstOrFail()->public_id;
                    }
                }

                $applied = collect($ids)->flatMap(fn (string $id) => $applicationsByActivity->get($id, collect()))
                    ->filter(fn (VaccinationApplication|MedicineApplication $application) => $activity->catalog_id === null || $application->getAttribute('catalog_id') === $activity->catalog_id)
                    ->unique(fn (VaccinationApplication|MedicineApplication $application) => $application->occurred_at->setTimezone($through->getTimezone())->toDateString())
                    ->count();

                foreach ($this->dueDates($activity, $baseline, $through, $applied) as $due) {
                    $key = $activity->type.':'.$ids[array_key_last($ids)].':'.$due->toDateString();
                    yield $key => ['entity_id' => $activity->public_id, 'activity' => $activity->title, 'flock' => $plan->flock->code, 'due' => $due];
                }
            }
        }
    }

    private function fingerprint(FlockPlanActivity $activity): string
    {
        return serialize([
            $activity->catalog_id ?? $activity->title, $activity->timing_kind,
            $activity->start_day, $activity->end_day, $activity->start_week, $activity->end_week, $activity->interval_days,
        ]);
    }

    /** @return Generator<int, CarbonImmutable> */
    private function dueDates(FlockPlanActivity $activity, CarbonImmutable $baseline, CarbonImmutable $through, int $applied): Generator
    {
        $firstDay = match ($activity->timing_kind) {
            'day', 'day_recurrence' => $activity->start_day,
            'week' => $activity->start_week === null ? null : $activity->start_week * 7,
            'week_range' => $activity->end_week === null ? null : $activity->end_week * 7,
            default => null,
        };
        if ($firstDay === null) {
            return;
        }

        $lastDay = $activity->timing_kind === 'day_recurrence'
            ? ($activity->end_day ?? ($activity->end_week ?? 0) * 7)
            : $firstDay;
        $interval = max(1, $activity->interval_days ?? 1);

        for ($day = $firstDay + $applied * $interval; $day <= $lastDay; $day += $interval) {
            $due = $baseline->addDays($day - 1);
            if ($due->isAfter($through)) {
                break;
            }

            yield $due;
        }
    }
}
