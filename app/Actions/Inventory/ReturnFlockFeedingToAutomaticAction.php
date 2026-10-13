<?php

namespace App\Actions\Inventory;

use App\Interfaces\Clock;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Services\Inventory\FlockFeedingHistory;
use App\Services\Inventory\FlockFeedingResolver;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\RunLotsCommand;

final readonly class ReturnFlockFeedingToAutomaticAction
{
    public function __construct(
        private RunLotsCommand $commands, private FlockFeedingResolver $resolver,
        private FlockFeedingHistory $history, private FlockState $state,
        private FlockActivityJournal $journal, private Clock $clock,
    ) {}

    public function execute(Flock $flock, string $key, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, null, 'flock.feeding.automatic', $key, ['flock' => $flock->public_id],
            function (string $operationId) use ($flock, $actor, $source): array {
                $locked = Flock::query()->whereKey($flock->id)->lockForUpdate()->firstOrFail();
                $this->state->open($locked);
                $instant = $this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE);
                $date = $instant->startOfDay();
                $configuration = $this->resolver->configuration($locked, $date);
                $age = $this->resolver->age($configuration->baseline_date->toDateString(), $date);
                $stage = $this->resolver->anchor($configuration->stages, $age, $instant, $configuration->stage_snapshot);
                $change = new FlockFeedingChange;
                $change->forceFill([
                    ...$configuration->only(['flock_id', 'flock_plan_revision_id', 'baseline_date', 'stages']),
                    'operation_id' => $operationId, 'effective_date' => $date->toDateString(), 'mode' => 'automatic',
                    'feed_recipe_id' => $stage['recipe_id'], 'grams_per_bird' => $stage['grams_per_bird'],
                    'daily_quantity_grams' => null, 'anchor_day' => $age, 'stage_snapshot' => $stage, 'created_by' => $actor->id,
                ])->save();
                $snapshot = $change->only(['id', 'mode', 'feed_recipe_id', 'daily_quantity_grams', 'effective_date', 'stage_snapshot']);
                $this->history->record($change, $locked, $actor, 'flock_feeding_automatic_restored', $operationId,
                    $configuration->only(['mode', 'feed_recipe_id', 'daily_quantity_grams']), $snapshot, $source);
                $this->journal->record($locked, $operationId, 'feeding_automatic', 'flock.feeding.automatic');

                return ['feeding' => $snapshot];
            });
    }
}
