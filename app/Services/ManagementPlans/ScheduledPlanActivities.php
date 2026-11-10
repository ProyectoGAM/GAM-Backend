<?php

namespace App\Services\ManagementPlans;

use App\Enums\Lots\FlockStatus;
use App\Enums\ManagementPlans\ScheduledActivityType;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\FlockPlanRevision;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Execution array{id: int, flock_id: int, activity_id: string, catalog_id: ?int, practice_type: ?string, local_day: string, recorded_at: string, record_order: int, invalidated_at: ?string, invalidation_order: int}
 * @phpstan-type Pending array{entity_id: string, activity: string, flock: string, flock_id: string, due: CarbonImmutable, invalidated: bool}
 */
final class ScheduledPlanActivities
{
    /** @return Generator<string, Pending> */
    public function pending(ScheduledActivityType $type, int $unitId, CarbonImmutable $evaluatedAt, CarbonImmutable $through): Generator
    {
        $eligible = function ($activities) use ($type): void {
            $activities->where('type', $type->planType())->where('conditional', false)->where('timing_kind', '!=', 'unscheduled');
            if ($type === ScheduledActivityType::BeakTrimming) {
                $activities->where('practice_type', $type->value);
            } elseif ($type === ScheduledActivityType::ManualPractice) {
                $activities->where(fn ($practices) => $practices->whereNull('practice_type')->orWhere('practice_type', '!=', 'beak_trimming'));
            }
        };
        $plans = FlockPlan::query()
            ->whereHas('flock', fn ($flocks) => $flocks->where('production_unit_id', $unitId)
                ->where('status', '!=', FlockStatus::Finished)->where('established_at', '<=', $evaluatedAt->toIso8601String()))
            ->whereHas('revisions.activities', $eligible)
            ->with(['flock', 'revisions.activities' => $eligible]);

        foreach ($plans->lazyById(50)->chunk(50) as $batch) {
            $executions = collect($this->executions($type, $batch->pluck('flock_id')->all(), $evaluatedAt))->groupBy('flock_id');
            foreach ($batch as $plan) {
                yield from $this->pendingForPlan($type, $plan, $executions->get($plan->flock_id)?->all() ?? [], $through);
            }
        }
    }

    /**
     * @param  list<int>  $flockIds
     * @return array<int, Execution>
     */
    private function executions(ScheduledActivityType $type, array $flockIds, CarbonImmutable $evaluatedAt): array
    {
        [$table, $flockColumn, $catalogColumn] = match ($type) {
            ScheduledActivityType::Vaccination => ['vaccination_applications', 'flock_id', 'records.vaccine_id'],
            ScheduledActivityType::Medication => ['medicine_applications', 'flock_id', 'records.medicine_id'],
            ScheduledActivityType::BeakTrimming, ScheduledActivityType::ManualPractice => ['manual_practices', 'flock_id', 'NULL'],
            ScheduledActivityType::FlockMovement => ['flock_movements', 'source_flock_id', 'NULL'],
            ScheduledActivityType::EggCollection => ['egg_collections', 'flock_id', 'NULL'],
            ScheduledActivityType::Mortality => ['mortality_records', 'flock_id', 'NULL'],
        };
        $practices = $type->planType() === ScheduledActivityType::ManualPractice->value;
        $query = DB::table($table.' as records')->whereIn('records.'.$flockColumn, $flockIds)
            ->where('records.occurred_at', '<=', $evaluatedAt->toIso8601String())
            ->selectRaw("records.id, records.{$flockColumn} as flock_id, records.occurred_at, records.created_at, {$catalogColumn} as catalog_id")
            ->selectRaw(($practices ? 'records.practice_type' : 'NULL').' as practice_type');

        if (in_array($type, [ScheduledActivityType::FlockMovement, ScheduledActivityType::EggCollection, ScheduledActivityType::Mortality], true)) {
            $query->join('flock_plan_activities as activity', 'activity.id', '=', 'records.flock_plan_activity_id')
                ->addSelect('activity.public_id as activity_id');
            if ($type === ScheduledActivityType::FlockMovement) {
                $query->whereNotExists(fn (Builder $reversal) => $reversal->selectRaw('1')->from('flock_movements as reversal')
                    ->whereColumn('reversal.reverses_movement_id', 'records.id'));
            } else {
                $query->where('records.status', 'recorded');
            }
        } else {
            $query->whereNotNull('records.plan_activity_id')->addSelect('records.plan_activity_id as activity_id');
            if ($type === ScheduledActivityType::BeakTrimming) {
                $query->where('records.practice_type', $type->value)
                    ->leftJoin('management_execution_corrections as correction', function (JoinClause $join) use ($evaluatedAt): void {
                        $join->on('correction.original_public_id', '=', 'records.public_id')
                            ->where('correction.original_type', ScheduledActivityType::ManualPractice->value)
                            ->where('correction.occurred_at', '<=', $evaluatedAt->toIso8601String());
                    })
                    ->leftJoin('flock_operations as recorded', 'recorded.operation_id', '=', 'records.operation_id')
                    ->leftJoin('flock_operations as invalidated', 'invalidated.operation_id', '=', 'correction.operation_id')
                    ->selectRaw('correction.occurred_at as invalidated_at, recorded.id as record_order, invalidated.id as invalidation_order');
            } else {
                $query->whereNotExists(fn (Builder $correction) => $correction->selectRaw('1')->from('management_execution_corrections as correction')
                    ->where('correction.original_type', $type->value)->whereColumn('correction.original_public_id', 'records.public_id'));
            }
        }

        return $query->get()->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'flock_id' => (int) $row->flock_id,
            'activity_id' => (string) $row->activity_id,
            'catalog_id' => $row->catalog_id === null ? null : (int) $row->catalog_id,
            'practice_type' => $row->practice_type === null ? null : (string) $row->practice_type,
            'local_day' => CarbonImmutable::parse($row->occurred_at)->setTimezone($evaluatedAt->getTimezone())->toDateString(),
            'recorded_at' => (string) ($row->created_at ?? $row->occurred_at),
            'record_order' => (int) ($row->record_order ?? 0),
            'invalidated_at' => isset($row->invalidated_at) ? (string) $row->invalidated_at : null,
            'invalidation_order' => (int) ($row->invalidation_order ?? 0),
        ])->all();
    }

    /**
     * @param  array<int, Execution>  $executions
     * @return Generator<string, Pending>
     */
    private function pendingForPlan(ScheduledActivityType $type, FlockPlan $plan, array $executions, CarbonImmutable $through): Generator
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
        $executionsByActivity = collect($executions)->groupBy('activity_id');

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

                $linked = collect($ids)->flatMap(fn (string $id) => $executionsByActivity->get($id)?->all() ?? [])
                    ->filter(fn (array $execution) => ($activity->catalog_id === null || $execution['catalog_id'] === $activity->catalog_id)
                        && ($activity->practice_type === null || $execution['practice_type'] === $activity->practice_type));
                [$completed, $invalidated] = $type === ScheduledActivityType::BeakTrimming
                    ? $this->beakTrimmingProgress($linked)
                    : [[], []];
                $firstPending = $type === ScheduledActivityType::BeakTrimming ? 0 : $linked->unique('local_day')->count();

                foreach ($this->dueDates($activity, $baseline, $through, $firstPending, max([-1, ...array_keys($invalidated)])) as $index => $due) {
                    if (isset($completed[$index]) || ($due->isAfter($through) && ! isset($invalidated[$index]))) {
                        continue;
                    }

                    $key = $type->value.':'.$ids[array_key_last($ids)].':'.$due->toDateString();
                    yield $key => [
                        'entity_id' => $activity->public_id, 'activity' => $activity->title,
                        'flock' => $plan->flock->code, 'flock_id' => $plan->flock->public_id,
                        'due' => $due, 'invalidated' => isset($invalidated[$index]),
                    ];
                }
            }
        }
    }

    /**
     * Reabre la ocurrencia acreditada por el registro invalidado, incluso en una pauta recurrente.
     *
     * @param  iterable<Execution>  $executions
     * @return array{array<int, int>, array<int, true>}
     */
    private function beakTrimmingProgress(iterable $executions): array
    {
        $events = [];
        foreach ($executions as $execution) {
            $events[] = [$execution['recorded_at'], $execution['record_order'], false, $execution['id'], $execution['local_day']];
            if ($execution['invalidated_at'] !== null) {
                $events[] = [$execution['invalidated_at'], $execution['invalidation_order'], true, $execution['id'], $execution['local_day']];
            }
        }
        sort($events);
        $byDay = $byRecord = $completed = $invalidated = [];
        $next = 0;

        foreach ($events as [, , $invalidate, $id, $day]) {
            if (! $invalidate) {
                $index = $byDay[$day] ??= $next;
                $byRecord[$id] = $index;
                $completed[$index] = ($completed[$index] ?? 0) + 1;
                unset($invalidated[$index]);
                while (isset($completed[$next])) {
                    $next++;
                }

                continue;
            }

            $index = $byRecord[$id];
            if (--$completed[$index] === 0) {
                unset($completed[$index], $byDay[$day]);
                $invalidated[$index] = true;
                $next = min($next, $index);
            }
        }

        return [$completed, $invalidated];
    }

    private function fingerprint(FlockPlanActivity $activity): string
    {
        return serialize([
            $activity->catalog_id ?? $activity->title, $activity->practice_type, $activity->timing_kind,
            $activity->start_day, $activity->end_day, $activity->start_week, $activity->end_week, $activity->interval_days,
        ]);
    }

    /** @return Generator<int, CarbonImmutable> */
    private function dueDates(FlockPlanActivity $activity, CarbonImmutable $baseline, CarbonImmutable $through, int $firstPending, int $lastInvalidated): Generator
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

        for ($index = $firstPending, $day = $firstDay + $index * $interval; $day <= $lastDay; $index++, $day += $interval) {
            $due = $baseline->addDays($day - 1);
            if ($due->isAfter($through) && $index > $lastInvalidated) {
                break;
            }

            yield $index => $due;
        }
    }
}
