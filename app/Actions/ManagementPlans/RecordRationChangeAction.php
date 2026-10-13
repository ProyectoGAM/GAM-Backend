<?php

namespace App\Actions\ManagementPlans;

use App\Actions\Inventory\ChangeFlockFeedingAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Inventory\FeedRecipe;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\ManagementPlans\RationChange;
use App\Models\User;
use App\Services\Inventory\FlockFeedingResolver;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\RunLotsCommand;
use Illuminate\Support\Str;

final readonly class RecordRationChangeAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private ResolveManagementExecutionContext $contextResolver,
        private FlockActivityJournal $activities,
        private AuditRecorder $auditRecorder,
        private ChangeFlockFeedingAction $feeding,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(Flock $flock, array $attributes, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'management-plans.execute',
            'management-plans.ration.change',
            $attributes['idempotency_key'],
            ['flock' => $flock->public_id, ...$attributes],
            function (string $operationId) use ($flock, $attributes, $actor, $source): array {
                $context = $this->contextResolver->execute($flock, $actor, $attributes, 'ration_change');
                $recipeSnapshot = null;
                if ($context->occurredAt->setTimezone(FlockFeedingResolver::TIMEZONE)->toDateString() !== now(FlockFeedingResolver::TIMEZONE)->toDateString()) {
                    throw new LotsConflict('El cambio de alimentación debe pertenecer al día del registro.');
                }
                $recipeId = $attributes['feed_recipe_id'] ?? null;
                if ($recipeId !== null) {
                    $recipe = FeedRecipe::query()->whereKey($recipeId)->sharedLock()->firstOrFail();
                    if ($recipe->status !== FeedRecipeStatus::Active) {
                        throw new LotsConflict('La receta seleccionada debe estar activa.');
                    }
                    $recipeSnapshot = [
                        'id' => (int) $recipe->getKey(),
                        'name' => $recipe->name,
                        'status' => $recipe->status->value,
                    ];
                }

                $feedingData = [];
                if ($recipeId !== null) {
                    $feedingData['receta_id'] = $recipeId;
                }
                if (array_key_exists('daily_quantity_grams', $attributes)) {
                    $feedingData['cantidad_diaria_g'] = $attributes['daily_quantity_grams'];
                }
                $feedingSnapshot = $this->feeding->apply($context->flock, $feedingData, $actor, $operationId, $source);
                if ($recipeSnapshot === null) {
                    $recipe = FeedRecipe::query()->whereKey($feedingSnapshot['feed_recipe_id'])->sharedLock()->firstOrFail();
                    $recipeSnapshot = ['id' => $recipe->id, 'name' => $recipe->name, 'status' => $recipe->status->value];
                }

                $change = new RationChange;
                $change->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'flock_id' => $context->flock->getKey(),
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_snapshot' => $context->planActivityTitleSnapshot,
                    'ration_description' => trim($attributes['ration_description']),
                    'feed_recipe_id' => $feedingSnapshot['feed_recipe_id'],
                    'feed_recipe_snapshot' => $recipeSnapshot,
                    'daily_quantity_grams' => $feedingSnapshot['daily_quantity_grams'],
                    'occurred_at' => $context->occurredAt,
                    'responsible_user_id' => $context->responsibleUserId,
                    'responsible_name_snapshot' => $context->responsibleNameSnapshot,
                    'notes' => $attributes['notes'] ?? null,
                    'created_by' => $actor->getKey(),
                    'operation_id' => $operationId,
                ])->save();
                $snapshot = [
                    'id' => $change->public_id,
                    'flock_id' => $context->flock->public_id,
                    'plan_activity_id' => $context->planActivityPublicId,
                    'plan_activity_title_at_application' => $context->planActivityTitleSnapshot,
                    'ration_description' => $change->ration_description,
                    'feed_recipe' => $recipeSnapshot,
                    'daily_quantity_grams' => (string) $feedingSnapshot['daily_quantity_grams'],
                    'occurred_at' => $context->occurredAt->toIso8601String(),
                    'responsible' => [
                        'id' => $context->responsibleUserId,
                        'name_at_application' => $context->responsibleNameSnapshot,
                    ],
                    'notes' => $change->notes,
                    'created_by' => (int) $actor->getKey(),
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $change,
                    actor: $actor,
                    logName: 'management_plans',
                    event: 'ration_change_recorded',
                    description: 'Cambio de ración registrado',
                    operationId: $operationId,
                    source: $source,
                    upId: $context->flock->production_unit_id,
                    properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => [], 'new' => $snapshot],
                ));
                $this->activities->record($context->flock, $operationId, 'ration_change', 'management-plans.ration.change');

                return ['ration_change' => $snapshot, 'warnings' => []];
            },
        );
    }
}
