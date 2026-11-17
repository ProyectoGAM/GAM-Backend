<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Inventory\FeedBalanceEvaluator;
use App\Services\Inventory\FeedIngredientStocks;
use App\Services\Inventory\FeedManufactureAlerts;
use App\Services\Inventory\FeedOperationRunner;
use App\Services\Inventory\FeedRecipeCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

final readonly class ManufactureFeedAction
{
    public function __construct(
        private FeedOperationRunner $operations,
        private FeedIngredientStocks $stocks,
        private FeedRecipeCalculator $calculator,
        private RecordInventoryMovementAction $recordMovement,
        private FeedBalanceEvaluator $evaluator,
        private ProductionUnitAccess $access,
        private FeedManufactureAlerts $alerts,
    ) {}

    public function execute(int $recipeId, int $houseId, mixed $quantity, string $key, User $actor, string $source = 'api', ?string $shortageConfirmation = null): FeedStockOperation
    {
        $quantity = $this->operations->grams($quantity, true);

        $payload = [$recipeId, $houseId, (string) $quantity];
        $idempotencyPayload = $shortageConfirmation === null ? $payload : [...$payload, $shortageConfirmation];

        $operation = $this->operations->run('manufacture', $key, $idempotencyPayload, $actor,
            function (string $operationId) use ($recipeId, $houseId, $quantity, $key, $actor, $source, $shortageConfirmation, $payload): FeedStockOperation {
                $house = PoultryHouse::query()->findOrFail($houseId);
                $unitId = (int) $house->production_unit_id;
                $this->access->assertUnit($unitId, $actor);
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
                $locations = $this->stocks->locations($unitId, [$houseId], true);
                $house->refresh();
                $balances = $this->stocks->balances(array_keys($calculation['quantities']), $locations);
                ['distribution' => $distribution, 'shortages' => $shortages] = $this->stocks->distribute($calculation['quantities'], $houseId, $locations, $balances);
                $shortageSnapshot = [];
                if ($shortages !== []) {
                    foreach ($shortages as $productId => $missingGrams) {
                        $shortageSnapshot[] = ['product_id' => $productId, 'name' => $products->get($productId)->name, 'quantity_grams' => $missingGrams];
                    }

                    $confirmation = hash_hmac('sha256', json_encode([
                        'feed-manufacture-shortage', $actor->getKey(), strtolower($key), $unitId, $payload, $calculation, $distribution,
                    ], JSON_THROW_ON_ERROR), Crypt::getKey());
                    if (! hash_equals($confirmation, $shortageConfirmation ?? '')) {
                        throw FeedRecipeConflict::stockConfirmationRequired($confirmation, $shortageSnapshot);
                    }
                }
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
                    'shortages' => $shortageSnapshot,
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
                $this->operations->audit($operation, $actor, $shortages === [] ? 'Alimento elaborado inmediatamente' : 'Alimento elaborado con faltantes confirmados', $source);
                if ($shortages !== []) {
                    $this->alerts->publish($operation);
                }
                $this->evaluator->evaluate($feedBalance, 'manufacture', $operationId);

                return $operation;
            });
        $this->access->assertUnit($operation->production_unit_id, $actor);

        return $operation;
    }
}
