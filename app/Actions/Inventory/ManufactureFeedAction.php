<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\Services\Inventory\FeedBalanceEvaluator;
use App\Services\Inventory\FeedIngredientStocks;
use App\Services\Inventory\FeedOperationRunner;
use App\Services\Inventory\FeedRecipeCalculator;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class ManufactureFeedAction
{
    public function __construct(
        private FeedOperationRunner $operations,
        private FeedIngredientStocks $stocks,
        private FeedRecipeCalculator $calculator,
        private RecordInventoryMovementAction $recordMovement,
        private FeedBalanceEvaluator $evaluator,
    ) {}

    public function execute(int $recipeId, int $houseId, mixed $quantity, string $key, User $actor, string $source = 'api'): FeedStockOperation
    {
        $quantity = $this->operations->grams($quantity, true);

        return $this->operations->run('manufacture', $key, [$recipeId, $houseId, (string) $quantity], $actor,
            function (string $operationId) use ($recipeId, $houseId, $quantity, $key, $actor, $source): FeedStockOperation {
                $recipe = $this->operations->recipe($recipeId)->load('ingredients');
                try {
                    $calculation = $this->calculator->calculate($recipe->yield_grams, $quantity, $recipe->ingredients->pluck('quantity_grams', 'product_id')->all());
                } catch (InvalidArgumentException $exception) {
                    throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
                }
                foreach ($calculation['quantities'] as $required) {
                    $this->operations->grams($required, true);
                }
                $products = $this->stocks->products(array_keys($calculation['quantities']));
                $house = PoultryHouse::query()->findOrFail($houseId);
                $unitId = (int) $house->production_unit_id;
                $locations = $this->stocks->locations($unitId, [$houseId], true);
                $house->refresh();
                $balances = $this->stocks->balances(array_keys($calculation['quantities']), $locations);
                $distribution = $this->stocks->distribute($calculation['quantities'], $houseId, $locations, $balances);
                $feedBalance = $this->operations->balance($recipeId, $unitId);
                $before = $feedBalance->quantity_grams;
                $after = $this->operations->grams($before + $quantity);
                $snapshot = [
                    'recipe_id' => $recipeId, 'recipe_name' => $recipe->name, 'yield_grams' => (string) $recipe->yield_grams,
                    'plant_name' => $house->name,
                    'ingredients' => $recipe->ingredients->map(fn ($ingredient): array => [
                        'product_id' => (int) $ingredient->product_id, 'name' => $products->get($ingredient->product_id)->name,
                        'sku' => $products->get($ingredient->product_id)->sku,
                        'base_quantity_grams' => (string) $ingredient->quantity_grams,
                        'required_quantity_grams' => (string) $calculation['quantities'][$ingredient->product_id],
                    ])->all(),
                    'distribution' => $distribution,
                ];
                $operation = $this->operations->operation('manufacture', $key, $actor, $operationId, [
                    'feed_recipe_id' => $recipeId, 'production_unit_id' => $unitId, 'poultry_house_id' => $houseId,
                    'quantity_grams' => $quantity, 'factor' => $calculation['factor'],
                    'balance_before' => (string) $before, 'balance_after' => (string) $after,
                    'delta_grams' => (string) $quantity, 'snapshot' => $snapshot,
                ]);
                $operation->save();
                $movement = $this->recordMovement->execute(new InventoryMovementCommand(
                    type: InventoryMovementType::Issue,
                    lines: array_map(fn (array $line): array => [
                        'product_id' => $line['product_id'], 'stock_location_id' => $line['stock_location_id'],
                        'on_hand_delta' => (string) BigDecimal::of($line['quantity_grams'])->negated(),
                    ], $distribution),
                    operationId: $operationId, referenceType: 'feed_manufacture', referenceId: (string) $operation->id,
                    reason: 'Ingredientes utilizados en elaboración de alimento',
                    occurredAt: $operation->occurred_at->toIso8601String(),
                ), $actor, $source, requiredPermission: null);
                $snapshot['distribution'] = array_map(function (array $line) use ($movement): array {
                    $movementLine = $movement->lines->first(fn ($item): bool => (int) $item->product_id === $line['product_id']
                        && (int) $item->stock_location_id === $line['stock_location_id']);

                    return [...$line, 'movement_line_id' => (int) $movementLine->id];
                }, $distribution);
                $operation->forceFill(['inventory_movement_id' => $movement->id, 'snapshot' => $snapshot])->save();
                $feedBalance->forceFill(['quantity_grams' => $after])->save();
                $this->operations->audit($operation, $actor, 'Alimento elaborado inmediatamente', $source);
                $this->evaluator->evaluate($feedBalance, 'manufacture', $operationId);

                return $operation;
            });
    }
}
