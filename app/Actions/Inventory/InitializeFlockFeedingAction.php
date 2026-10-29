<?php

namespace App\Actions\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanRevision;
use App\Models\User;
use App\Services\Inventory\FlockFeedingHistory;
use App\Services\Inventory\FlockFeedingResolver;
use Carbon\CarbonImmutable;

final readonly class InitializeFlockFeedingAction
{
    public function __construct(private FlockFeedingResolver $resolver, private FlockFeedingHistory $history, private Clock $clock) {}

    public function execute(Flock $flock, FlockPlanRevision $revision, User $actor, string $operationId, string $source = 'api', ?Flock $parent = null, ?CarbonImmutable $effectiveAt = null): FlockFeedingChange
    {
        $plan = FlockPlan::query()->findOrFail($revision->flock_plan_id);
        $instant = ($effectiveAt ?? $this->clock->now())->setTimezone(FlockFeedingResolver::TIMEZONE);
        $date = $instant->startOfDay();
        $stages = $revision->activities()->where('type', 'ration_change')->get()->map(fn ($activity): array => [
            'activity_id' => $activity->public_id, 'recipe_id' => $activity->feed_recipe_id,
            'grams_per_bird' => $activity->grams_per_bird,
            'start_day' => $activity->start_day ?? (($activity->start_week - 1) * 7 + 1),
            'title' => $activity->title, 'recipe_snapshot' => $activity->catalog_snapshot,
        ])->sortBy('start_day')->values()->all();
        $initialCount = count(array_filter($stages, fn (array $stage): bool => $stage['start_day'] === 1));
        if ($initialCount !== 1 || collect($stages)->contains(fn (array $stage): bool => $stage['recipe_id'] === null || $stage['grams_per_bird'] === null)) {
            throw new LotsConflict('El plan debe tener exactamente una alimentación inicial válida en día 1 o semana 1.');
        }
        $previous = FlockFeedingChange::query()->where('flock_id', $flock->id)->orderByDesc('effective_date')->orderByDesc('id')->first();
        $inherited = $parent === null ? null : $this->resolver->resolve($parent, $date);
        if ($previous === null && $parent === null) {
            $recipe = FeedRecipe::query()->whereKey($stages[0]['recipe_id'])->sharedLock()->firstOrFail();
            if ($recipe->status !== FeedRecipeStatus::Active) {
                throw new LotsConflict('La receta de alimentación inicial debe estar activa.');
            }
        }
        $day = $this->resolver->age($plan->baseline_date->toDateString(), $date);
        $prior = $previous === null ? null : $this->resolver->resolve($flock, $date);
        $manual = ($inherited['configuration']->mode ?? $previous?->mode) === 'manual';
        $stage = $inherited['stage'] ?? $this->resolver->anchor($stages, $day, $instant, $prior['stage'] ?? null);
        if ($inherited !== null) {
            $stage = collect($stages)->first(fn (array $copy): bool => $copy['start_day'] === $stage['start_day'] && $copy['recipe_id'] === $stage['recipe_id']) ?? $stage;
        }
        $change = new FlockFeedingChange;
        $change->forceFill([
            'flock_id' => $flock->id, 'flock_plan_revision_id' => $revision->id,
            'operation_id' => $operationId, 'effective_date' => $date->toDateString(),
            'baseline_date' => $plan->baseline_date->toDateString(), 'mode' => $manual ? 'manual' : 'automatic',
            'feed_recipe_id' => $manual ? ($inherited['recipe_id'] ?? $previous->feed_recipe_id) : $stage['recipe_id'],
            'grams_per_bird' => $stage['grams_per_bird'],
            'daily_quantity_grams' => $manual ? ($inherited['quantity_grams'] ?? $previous->daily_quantity_grams) : null,
            'anchor_day' => $day, 'stage_snapshot' => $stage, 'stages' => $stages, 'created_by' => $actor->id,
        ])->save();
        $this->history->record($change, $flock, $actor, $previous === null ? 'flock_feeding_initialized' : 'flock_feeding_plan_revised', $operationId,
            $previous === null ? [] : $previous->only(['mode', 'feed_recipe_id', 'daily_quantity_grams', 'stages']),
            $change->only(['mode', 'feed_recipe_id', 'daily_quantity_grams', 'grams_per_bird', 'stages', 'stage_snapshot', 'effective_date']), $source);

        return $change;
    }
}
