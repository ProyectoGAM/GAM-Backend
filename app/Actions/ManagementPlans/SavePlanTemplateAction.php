<?php

namespace App\Actions\ManagementPlans;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateActivity;
use App\Models\ManagementPlans\PlanTemplateVersion;
use App\Models\User;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\RunLotsCommand;
use App\Services\ManagementPlans\PlanActivityNormalizer;
use Illuminate\Support\Str;

final readonly class SavePlanTemplateAction
{
    public function __construct(private RunLotsCommand $commands, private PlanActivityNormalizer $activities, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.manage', 'management_plan.template.create', $data['idempotency_key'], $data, function (string $operationId) use ($data, $actor, $source): array {
            $template = new PlanTemplate;
            $template->forceFill([
                'public_id' => (string) Str::ulid(), 'name' => trim($data['name']),
                'description' => $data['description'] ?? null, 'status' => 'active',
                'current_version' => 1, 'created_by' => $actor->id,
            ])->save();
            $version = $this->version($template, 1, $template->name, $template->description, $data['activities'], $actor, $operationId);
            $snapshot = $this->snapshot($template, $version);
            $this->history->audit($template, $actor, 'plan_template_created', 'Plantilla de manejo creada', $operationId, [], $snapshot, null, $source);

            return ['template' => $snapshot];
        });
    }

    /** @param array<string, mixed> $data */
    public function revise(PlanTemplate $template, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.manage', 'management_plan.template.revise', $data['idempotency_key'], ['template' => $template->public_id, ...$data], function (string $operationId) use ($template, $data, $actor, $source): array {
            $current = PlanTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active' || $current->current_version !== (int) $data['expected_version']) {
                throw new LotsConflict('La plantilla cambió o fue retirada.');
            }
            $before = $this->snapshot($current);
            $previous = PlanTemplateVersion::query()->where('plan_template_id', $current->id)->where('number', $current->current_version)->firstOrFail();
            if ($previous->status === 'draft') {
                $previous->status = 'retired';
                $previous->save();
            }
            $current->forceFill([
                'current_version' => $current->current_version + 1,
                'name' => isset($data['name']) ? trim($data['name']) : $current->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $current->description,
            ])->save();
            $version = $this->version($current, $current->current_version, $current->name, $current->description, $data['activities'], $actor, $operationId);
            $snapshot = $this->snapshot($current, $version);
            $this->history->audit($current, $actor, 'plan_template_revised', 'Nueva versión de plantilla de manejo', $operationId, $before, $snapshot, null, $source);

            return ['template' => $snapshot];
        });
    }

    /** @param array<string, mixed> $data */
    public function publish(PlanTemplate $template, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.manage', 'management_plan.template.publish', $data['idempotency_key'], ['template' => $template->public_id, ...$data], function (string $operationId) use ($template, $data, $actor, $source): array {
            $current = PlanTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active' || $current->current_version !== (int) $data['expected_version']) {
                throw new LotsConflict('La plantilla cambió o fue retirada.');
            }
            $version = PlanTemplateVersion::query()->where('plan_template_id', $current->id)->where('number', $current->current_version)->with('activities')->firstOrFail();
            if ($version->status !== 'draft') {
                throw new LotsConflict('La versión actual no está en borrador.');
            }
            $before = $this->snapshot($current);
            if ($current->published_version !== null) {
                PlanTemplateVersion::query()->where('plan_template_id', $current->id)
                    ->where('number', $current->published_version)->update(['status' => 'retired']);
            }
            $version->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $current->published_version = $version->number;
            $current->save();
            $snapshot = $this->snapshot($current, $version);
            $this->history->audit($current, $actor, 'plan_template_published', 'Plantilla de manejo publicada', $operationId, $before, $snapshot, null, $source);

            return ['template' => $snapshot];
        });
    }

    /** @param array<string, mixed> $data */
    public function retire(PlanTemplate $template, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'management-plans.manage', 'management_plan.template.retire', $data['idempotency_key'], ['template' => $template->public_id, ...$data], function (string $operationId) use ($template, $data, $actor, $source): array {
            $current = PlanTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active' || $current->current_version !== (int) $data['expected_version']) {
                throw new LotsConflict('La plantilla cambió o fue retirada.');
            }
            $before = $this->snapshot($current);
            $current->status = 'retired';
            $current->save();
            $snapshot = $this->snapshot($current);
            $this->history->audit($current, $actor, 'plan_template_retired', 'Plantilla de manejo retirada', $operationId, $before, $snapshot, null, $source);

            return ['template' => $snapshot];
        });
    }

    /** @param array<int, array<string, mixed>> $activities */
    private function version(PlanTemplate $template, int $number, string $name, ?string $description, array $activities, User $actor, string $operationId): PlanTemplateVersion
    {
        $version = new PlanTemplateVersion;
        $version->forceFill([
            'plan_template_id' => $template->id, 'number' => $number,
            'name' => $name, 'description' => $description, 'status' => 'draft',
            'created_by' => $actor->id, 'operation_id' => $operationId,
        ])->save();
        foreach ($activities as $index => $activity) {
            $record = new PlanTemplateActivity;
            $record->forceFill([
                'public_id' => (string) Str::ulid(), 'plan_template_version_id' => $version->id,
                ...$this->activities->normalize($activity, $index + 1),
            ])->save();
        }

        return $version->load('activities');
    }

    /** @return array<string, mixed> */
    public function snapshot(PlanTemplate $template, ?PlanTemplateVersion $version = null): array
    {
        $version ??= PlanTemplateVersion::query()->where('plan_template_id', $template->id)->where('number', $template->current_version)->with('activities')->firstOrFail();
        $version->loadMissing('activities');

        return [
            'id' => $template->public_id, 'name' => $version->name,
            'description' => $version->description, 'status' => $template->status,
            'current_version' => $template->current_version,
            'published_version' => $template->published_version,
            'version_status' => $version->status,
            'activities' => $version->activities->map(fn (PlanTemplateActivity $activity): array => [
                'id' => $activity->public_id, 'type' => $activity->type, 'title' => $activity->title,
                'timing_kind' => $activity->timing_kind, 'start_day' => $activity->start_day,
                'end_day' => $activity->end_day, 'start_week' => $activity->start_week,
                'end_week' => $activity->end_week, 'interval_days' => $activity->interval_days,
                'conditional' => $activity->conditional, 'condition' => $activity->condition,
                'notes' => $activity->notes, 'catalog_type' => $activity->catalog_type,
                'catalog_snapshot' => $activity->catalog_snapshot,
            ])->all(),
        ];
    }
}
