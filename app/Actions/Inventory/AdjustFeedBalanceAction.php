<?php

namespace App\Actions\Inventory;

use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\Services\Inventory\FeedOperationRunner;
use App\ValueObjects\Inventory\FeedStockGrams;
use InvalidArgumentException;

final readonly class AdjustFeedBalanceAction
{
    public function __construct(private FeedOperationRunner $operations) {}

    public function execute(int $recipeId, int $unitId, string $mode, mixed $value, string $reason, string $key, User $actor, string $source = 'api'): FeedStockOperation
    {
        $input = $this->operations->adjustment($mode, $value, $reason);

        return $this->operations->run('feed_adjustment', $key, [$recipeId, $unitId, $input], $actor,
            function (string $operationId) use ($recipeId, $unitId, $input, $key, $actor, $source): FeedStockOperation {
                $recipe = $this->operations->recipe($recipeId);
                ProductionUnit::query()->findOrFail($unitId);
                $balance = $this->operations->balance($recipeId, $unitId);
                $before = $balance->quantity_grams;
                try {
                    $after = FeedStockGrams::adjusted($before, $input['mode'], $input['value']);
                } catch (InvalidArgumentException $exception) {
                    throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
                }
                $balance->forceFill(['quantity_grams' => $after])->save();
                $operation = $this->operations->operation('feed_adjustment', $key, $actor, $operationId, [
                    'feed_recipe_id' => $recipeId, 'production_unit_id' => $unitId,
                    'quantity_grams' => $input['value'], 'reason' => $input['reason'], 'adjustment_mode' => $input['mode'],
                    'balance_before' => (string) $before, 'balance_after' => (string) $after,
                    'delta_grams' => (string) ($after - $before),
                    'snapshot' => ['recipe_id' => $recipeId, 'recipe_name' => $recipe->name],
                ]);
                $operation->save();
                $this->operations->audit($operation, $actor, 'Saldo de alimento ajustado independientemente', $source);

                return $operation;
            });
    }
}
