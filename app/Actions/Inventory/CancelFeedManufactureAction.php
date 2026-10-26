<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\User;
use App\Services\Inventory\FeedBalanceEvaluator;
use App\Services\Inventory\FeedIngredientStocks;
use App\Services\Inventory\FeedOperationRunner;
use Brick\Math\BigDecimal;

final readonly class CancelFeedManufactureAction
{
    public function __construct(
        private FeedOperationRunner $operations,
        private FeedIngredientStocks $stocks,
        private RecordInventoryMovementAction $recordMovement,
        private FeedBalanceEvaluator $evaluator,
    ) {}

    public function execute(int $manufactureId, string $reason, string $key, User $actor, string $source = 'api'): FeedStockOperation
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new FeedRecipeConflict('Debes indicar un motivo de hasta 2000 caracteres.');
        }
        $reason = trim($reason);

        return $this->operations->run('cancellation', $key, [$manufactureId, $reason], $actor,
            function (string $operationId) use ($manufactureId, $reason, $key, $actor, $source): FeedStockOperation {
                $original = FeedStockOperation::query()->whereKey($manufactureId)->where('kind', 'manufacture')->lockForUpdate()->firstOrFail();
                if ($original->cancellation()->exists()) {
                    throw new FeedRecipeConflict('La elaboración ya fue cancelada.');
                }
                $this->operations->recipe((int) $original->feed_recipe_id, false);
                $movement = InventoryMovement::query()->whereKey($original->inventory_movement_id)->lockForUpdate()->firstOrFail()->load('lines');
                if ($movement->reversal()->exists()) {
                    throw new FeedRecipeConflict('Los ingredientes de la elaboración ya fueron devueltos.');
                }
                $distribution = $original->snapshot['distribution'];
                $productIds = array_values(array_unique(array_column($distribution, 'product_id')));
                $houseIds = array_values(array_unique(array_column($distribution, 'poultry_house_id')));
                $this->stocks->products($productIds, false);
                $locations = $this->stocks->locations($original->production_unit_id, $houseIds);
                foreach ($distribution as $line) {
                    if ((int) $locations->get($line['stock_location_id'])?->poultry_house_id !== $line['poultry_house_id']) {
                        throw new FeedRecipeConflict('La ubicación original de devolución ya no pertenece a la planta.');
                    }
                }
                $ingredientBalances = $this->stocks->balances($productIds, $locations);
                $compensatingDistribution = array_map(function (array $line) use ($ingredientBalances): array {
                    $balance = $ingredientBalances->first(fn ($item): bool => (int) $item->product_id === $line['product_id']
                        && (int) $item->stock_location_id === $line['stock_location_id']);
                    $before = BigDecimal::of((string) $balance->on_hand_quantity);

                    return [...$line, 'balance_before' => (string) $before,
                        'balance_after' => (string) $before->plus($line['quantity_grams'])->toScale(6)];
                }, $distribution);
                $balance = $this->operations->balance((int) $original->feed_recipe_id, $original->production_unit_id);
                $before = $balance->quantity_grams;
                $after = $this->operations->grams($before - $original->quantity_grams);
                if ($after < 0) {
                    throw new FeedRecipeConflict('La cancelación dejaría el saldo de alimento negativo.');
                }
                $operation = $this->operations->operation('cancellation', $key, $actor, $operationId, [
                    'feed_recipe_id' => $original->feed_recipe_id, 'production_unit_id' => $original->production_unit_id,
                    'poultry_house_id' => $original->poultry_house_id, 'cancels_operation_id' => $original->id,
                    'quantity_grams' => $original->quantity_grams, 'factor' => $original->factor, 'reason' => $reason,
                    'balance_before' => (string) $before, 'balance_after' => (string) $after,
                    'delta_grams' => (string) -$original->quantity_grams,
                    'snapshot' => [...$original->snapshot, 'distribution' => $compensatingDistribution],
                ]);
                $operation->save();
                $compensation = $this->recordMovement->execute(new InventoryMovementCommand(
                    type: InventoryMovementType::Reversal,
                    lines: $movement->lines->map(fn ($line): array => [
                        'product_id' => (int) $line->product_id, 'stock_location_id' => (int) $line->stock_location_id,
                        'on_hand_delta' => (string) BigDecimal::of((string) $line->on_hand_delta)->negated(),
                    ])->all(),
                    operationId: $operationId, referenceType: 'feed_manufacture_cancellation', referenceId: (string) $operation->id,
                    reason: $reason, reversesMovementId: (int) $movement->id,
                    occurredAt: $operation->occurred_at->toIso8601String(),
                ), $actor, $source, $original);
                $compensatingLines = $compensation->lines->keyBy(fn (InventoryMovementLine $line): string => $line->product_id.':'.$line->stock_location_id);
                $compensatingDistribution = array_map(function (array $line) use ($compensatingLines): array {
                    $movementLine = $compensatingLines->get($line['product_id'].':'.$line['stock_location_id']);
                    if (! $movementLine instanceof InventoryMovementLine) {
                        throw new FeedRecipeConflict('No se encontró la línea compensatoria del ingrediente y la planta originales.');
                    }

                    return [...$line, 'movement_line_id' => (int) $movementLine->id];
                }, $compensatingDistribution);
                $operation->forceFill([
                    'inventory_movement_id' => $compensation->id,
                    'snapshot' => [...$original->snapshot, 'distribution' => $compensatingDistribution],
                ])->save();
                $balance->forceFill(['quantity_grams' => $after])->save();
                $this->operations->audit($operation, $actor, 'Elaboración cancelada con movimientos compensatorios', $source);
                $this->evaluator->evaluate($balance, 'cancellation', $operationId);

                return $operation;
            });
    }
}
