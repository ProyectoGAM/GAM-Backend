<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use App\Services\Inventory\FeedIngredientStocks;
use App\Services\Inventory\FeedOperationRunner;
use App\ValueObjects\Inventory\InventoryQuantity;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class AdjustFeedIngredientAction
{
    public function __construct(
        private FeedOperationRunner $operations,
        private FeedIngredientStocks $stocks,
        private RecordInventoryMovementAction $recordMovement,
    ) {}

    public function execute(int $houseId, int $productId, string $mode, mixed $value, string $reason, string $key, User $actor, string $source = 'api'): FeedStockOperation
    {
        $input = $this->operations->adjustment($mode, $value, $reason, true);

        return $this->operations->run('ingredient_adjustment', $key, [$houseId, $productId, $input], $actor,
            function (string $operationId) use ($houseId, $productId, $input, $key, $actor, $source): FeedStockOperation {
                $product = $this->stocks->products([$productId])->get($productId);
                $house = PoultryHouse::query()->findOrFail($houseId);
                $locations = $this->stocks->locations((int) $house->production_unit_id, [$houseId]);
                $balance = $this->stocks->balances([$productId], $locations)->sole();
                $before = BigDecimal::of((string) $balance->on_hand_quantity);
                $after = match ($input['mode']) {
                    'sumar' => $before->plus($input['value']),
                    'restar' => $before->minus($input['value']),
                    'establecer' => BigDecimal::of($input['value']),
                };
                $delta = $after->minus($before);
                try {
                    InventoryQuantity::from((string) $after, BaseUnit::Gram, false);
                    InventoryQuantity::from((string) $delta, BaseUnit::Gram);
                } catch (InvalidArgumentException $exception) {
                    throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
                }
                $operation = $this->operations->operation('ingredient_adjustment', $key, $actor, $operationId, [
                    'production_unit_id' => (int) $house->production_unit_id, 'poultry_house_id' => $houseId,
                    'product_id' => $productId, 'stock_location_id' => $balance->stock_location_id,
                    'quantity_grams' => $input['value'], 'reason' => $input['reason'], 'adjustment_mode' => $input['mode'],
                    'balance_before' => (string) $before, 'balance_after' => (string) $after, 'delta_grams' => (string) $delta,
                    'snapshot' => ['product_id' => $productId, 'product_name' => $product->name, 'sku' => $product->sku, 'plant_name' => $house->name],
                ]);
                $operation->save();
                if (! $delta->isZero()) {
                    $movement = $this->recordMovement->execute(new InventoryMovementCommand(
                        type: InventoryMovementType::Adjustment,
                        lines: [['product_id' => $productId, 'stock_location_id' => (int) $balance->stock_location_id, 'on_hand_delta' => (string) $delta]],
                        operationId: $operationId, referenceType: 'feed_ingredient_adjustment', referenceId: (string) $operation->id,
                        reason: $input['reason'], occurredAt: $operation->occurred_at->toIso8601String(),
                    ), $actor, $source, requiredPermission: null);
                    $operation->forceFill(['inventory_movement_id' => $movement->id])->save();
                }
                $this->operations->audit($operation, $actor, 'Ingrediente ajustado independientemente', $source);

                return $operation;
            });
    }
}
