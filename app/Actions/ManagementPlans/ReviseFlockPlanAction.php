<?php

namespace App\Actions\ManagementPlans;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\FlockPlanRevision;
use App\Models\ManagementPlans\PlanTemplateVersion;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\RunLotsCommand;
use App\Services\ManagementPlans\PlanActivityNormalizer;
use Illuminate\Support\Str;

final readonly class ReviseFlockPlanAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private AssignFlockPlanAction $assignment,
        private PlanActivityNormalizer $normalizer,
        private LotsHistory $history,
        private FlockActivityJournal $journal,
    ) {}

    /** @param array<string, mixed> $data */
    public function assignLegacy(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.manage', 'management_plan.flock.assign', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = Flock::query()->whereKey($flock->id)->lockForUpdate()->firstOrFail();
            $this->assignment->assignPublished($locked, $data['plan_template_id'], (int) $data['plan_template_version'], $actor, $operationId, $source);
            $plan = FlockPlan::query()->where('flock_id', $locked->id)->firstOrFail();
            $this->journal->record($locked, $operationId, 'plan_assignment', 'management_plan.flock.assign');

            return ['plan' => $this->snapshot($plan)];
        });
    }

    /** @param array<string, mixed> $data */
    public function revise(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.manage', 'management_plan.flock.revise', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = Flock::query()->whereKey($flock->id)->lockForUpdate()->firstOrFail();
            $plan = FlockPlan::query()->where('flock_id', $locked->id)->lockForUpdate()->first();
            if ($plan === null || $plan->current_revision !== (int) $data['expected_revision']) {
                throw new LotsConflict('El plan del lote no existe o cambió de revisión.');
            }
            $before = $this->snapshot($plan);
            $number = $plan->current_revision + 1;
            $revision = new FlockPlanRevision;
            $revision->forceFill([
                'public_id' => (string) Str::ulid(), 'flock_plan_id' => $plan->id,
                'number' => $number, 'operation_id' => $operationId,
                'reason' => trim($data['reason']), 'created_by' => $actor->id,
            ])->save();
            foreach ($data['activities'] as $index => $activity) {
                $row = new FlockPlanActivity;
                $row->forceFill([
                    'public_id' => (string) Str::ulid(), 'flock_plan_revision_id' => $revision->id,
                    ...$this->normalizer->normalize($activity, $index + 1),
                ])->save();
            }
            $plan->current_revision = $number;
            $plan->save();
            $after = $this->snapshot($plan);
            $this->history->audit($plan, $actor, 'flock_plan_revised', 'Plan de manejo del lote revisado', $operationId, $before, $after, $locked->production_unit_id, $source, $data['reason']);
            $this->journal->record($locked, $operationId, 'plan_revision', 'management_plan.flock.revise');

            return ['plan' => $after];
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(FlockPlan $plan, bool $includeRevisions = false): array
    {
        $plan->loadMissing('flock');
        $templateVersion = $plan->plan_template_version_id === null ? null : PlanTemplateVersion::query()->with('template')->find($plan->plan_template_version_id);
        $revisions = FlockPlanRevision::query()->where('flock_plan_id', $plan->id)
            ->when(! $includeRevisions, fn ($query) => $query->where('number', $plan->current_revision))
            ->with('activities')->orderBy('number')->get();

        return [
            'id' => $plan->public_id, 'flock_id' => $plan->flock->public_id,
            'baseline_date' => $plan->baseline_date->format('Y-m-d'),
            'current_revision' => $plan->current_revision,
            'source_template' => $templateVersion === null ? null : [
                'id' => $templateVersion->template->public_id, 'version' => $templateVersion->number,
                'name_at_assignment' => $templateVersion->name,
            ],
            'source_flock_id' => $plan->source_flock_id === null ? null : Flock::query()->find($plan->source_flock_id)?->public_id,
            'revisions' => $revisions->map(fn (FlockPlanRevision $revision): array => [
                'id' => $revision->public_id, 'number' => $revision->number,
                'reason' => $revision->reason, 'operation_id' => $revision->operation_id,
                'created_at' => $revision->created_at->toIso8601String(),
                'activities' => $revision->activities->map(fn (FlockPlanActivity $activity): array => [
                    'id' => $activity->public_id, 'type' => $activity->type,
                    'title' => $activity->title, 'timing_kind' => $activity->timing_kind,
                    'start_day' => $activity->start_day, 'end_day' => $activity->end_day,
                    'start_week' => $activity->start_week, 'end_week' => $activity->end_week,
                    'interval_days' => $activity->interval_days, 'conditional' => $activity->conditional,
                    'condition' => $activity->condition, 'notes' => $activity->notes,
                    'catalog_type' => $activity->catalog_type, 'catalog_snapshot' => $activity->catalog_snapshot,
                ])->all(),
            ])->all(),
        ];
    }
}
