<?php

namespace App\Actions\Inventory;

use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Services\Inventory\FeedOperationRunner;
use App\Services\Inventory\FlockFeedingHistory;
use App\Services\Inventory\FlockFeedingResolver;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\RunLotsCommand;

final readonly class ChangeFlockFeedingAction
{
    public function __construct(
        private RunLotsCommand $commands, private FlockFeedingResolver $resolver,
        private FeedOperationRunner $stock, private FlockFeedingHistory $history,
        private FlockState $state, private FlockActivityJournal $journal, private Clock $clock,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, null, 'flock.feeding.manual', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data],
            function (string $operationId) use ($flock, $data, $actor, $source): array {
                $locked = Flock::query()->whereKey($flock->id)->lockForUpdate()->firstOrFail();
                $snapshot = $this->apply($locked, $data, $actor, $operationId, $source);
                $this->journal->record($locked, $operationId, 'feeding_manual', 'flock.feeding.manual');

                return ['feeding' => $snapshot];
            });
    }

    /** El Action propietario invoca esta mutación dentro de su transacción y con el lote bloqueado.
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function apply(Flock $locked, array $data, User $actor, string $operationId, string $source = 'api'): array
    {
        $this->state->open($locked);
        $date = $this->clock->now()->setTimezone(FlockFeedingResolver::TIMEZONE)->startOfDay();
        if (! isset($data['receta_id']) && ! array_key_exists('cantidad_diaria_g', $data)) {
            throw new LotsConflict('Indica una receta, una cantidad diaria o ambas.');
        }
        $current = $this->resolver->resolve($locked, $date);
        $recipeId = isset($data['receta_id']) ? $this->stock->recipe((int) $data['receta_id'])->id : $current['recipe_id'];
        $quantity = $this->stock->grams($data['cantidad_diaria_g'] ?? $current['quantity_grams'], positive: true, allowNegative: false);
        $configuration = $current['configuration'];
        $change = new FlockFeedingChange;
        $change->forceFill([
            ...$configuration->only(['flock_id', 'flock_plan_revision_id', 'baseline_date', 'stages']),
            'operation_id' => $operationId, 'effective_date' => $date->toDateString(), 'mode' => 'manual',
            'feed_recipe_id' => $recipeId, 'grams_per_bird' => $current['stage']['grams_per_bird'],
            'daily_quantity_grams' => $quantity, 'anchor_day' => $current['age'],
            'stage_snapshot' => $current['stage'], 'created_by' => $actor->id,
        ])->save();
        $snapshot = $change->only(['id', 'mode', 'feed_recipe_id', 'daily_quantity_grams', 'effective_date', 'stage_snapshot']);
        $this->history->record($change, $locked, $actor, 'flock_feeding_manual_changed', $operationId,
            ['mode' => $configuration->mode, 'feed_recipe_id' => $current['recipe_id'], 'daily_quantity_grams' => $current['quantity_grams']], $snapshot, $source);

        return $snapshot;
    }
}
