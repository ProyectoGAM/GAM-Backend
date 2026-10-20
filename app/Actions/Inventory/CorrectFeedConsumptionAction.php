<?php

namespace App\Actions\Inventory;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedConsumptionCorrection;
use App\Models\Inventory\FeedRecipe;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Services\Inventory\FeedConsumptionStock;
use App\Services\Inventory\FeedOperationRunner;
use App\Services\Inventory\FlockFeedingHistory;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\RunLotsCommand;

final readonly class CorrectFeedConsumptionAction
{
    public function __construct(
        private RunLotsCommand $commands, private FeedConsumptionStock $stock,
        private FeedOperationRunner $operations, private FlockFeedingHistory $history,
        private FlockActivityJournal $journal,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(FeedConsumption $consumption, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, null, 'flock.feeding.correct', $data['idempotency_key'], ['consumption_id' => $consumption->id, ...$data],
            function (string $operationId) use ($consumption, $data, $actor, $source): array {
                $flock = Flock::query()->whereKey($consumption->flock_id)->lockForUpdate()->firstOrFail();
                $locked = FeedConsumption::query()->whereKey($consumption->id)->lockForUpdate()->firstOrFail();
                $reason = trim($data['motivo'] ?? '');
                if ($reason === '' || mb_strlen($reason) > 2000) {
                    throw new LotsConflict('Debes indicar un motivo de hasta 2000 caracteres.');
                }
                if (! isset($data['receta_id']) && ! array_key_exists('cantidad_g', $data)) {
                    throw new LotsConflict('Indica una receta, una cantidad rectificada o ambas.');
                }
                $before = $locked->effective();
                $recipeId = (int) ($data['receta_id'] ?? $before['recipe_id']);
                $quantity = $this->operations->grams($data['cantidad_g'] ?? $before['quantity_grams'], allowNegative: false);
                $recipes = FeedRecipe::query()->whereIn('id', [$before['recipe_id'], $recipeId])->orderBy('id')->sharedLock()->get();
                if (! $recipes->contains('id', $recipeId)) {
                    throw new LotsConflict('La receta indicada no existe.');
                }
                $correction = new FeedConsumptionCorrection;
                $correction->forceFill([
                    'feed_consumption_id' => $locked->id, 'feed_recipe_id' => $recipeId, 'quantity_grams' => $quantity,
                    'reason' => $reason, 'before_snapshot' => $before, 'operation_id' => $operationId,
                    'idempotency_key' => $data['idempotency_key'], 'request_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                    'created_by' => $actor->id,
                ])->save();
                $deltas = [$before['recipe_id'] => $before['quantity_grams']];
                $deltas[$recipeId] = ($deltas[$recipeId] ?? 0) - $quantity;
                $movements = $this->stock->apply($locked, $deltas, $operationId, $correction);
                $snapshot = [
                    'id' => $correction->id, 'consumption_id' => $locked->id,
                    'recipe_id' => $recipeId, 'quantity_grams' => (string) $quantity, 'reason' => $reason, 'movements' => $movements,
                ];
                $this->history->record($correction, $flock, $actor, 'feed_consumption_corrected', $operationId, $before, $snapshot, $source);
                $this->journal->record($flock, $operationId, 'feed_consumption_correction', 'flock.feeding.correct');

                return ['correction' => $snapshot];
            });
    }
}
