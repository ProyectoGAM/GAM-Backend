<?php

namespace App\Services\Inventory;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedConsumptionCorrection;
use App\Models\Inventory\FeedConsumptionMovement;
use App\ValueObjects\Inventory\FeedStockGrams;
use InvalidArgumentException;

final readonly class FeedConsumptionStock
{
    public function __construct(private FeedOperationRunner $operations, private FeedBalanceEvaluator $evaluator) {}

    /**
     * Bloquea saldos en orden de receta y anexa movimientos sin usar las restricciones de cancelación.
     *
     * @param  array<int, int>  $deltas
     * @return list<array<string, mixed>>
     */
    public function apply(FeedConsumption $consumption, array $deltas, string $operationId, ?FeedConsumptionCorrection $correction = null): array
    {
        ksort($deltas);
        $movements = [];
        foreach ($deltas as $recipeId => $delta) {
            if ($delta === 0) {
                continue;
            }
            $balance = $this->operations->balance($recipeId, $consumption->production_unit_id);
            $before = $balance->quantity_grams;
            try {
                $after = FeedStockGrams::parse($before + $delta);
            } catch (InvalidArgumentException $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
            $balance->forceFill(['quantity_grams' => $after])->save();
            $movement = new FeedConsumptionMovement;
            $movement->forceFill([
                'feed_consumption_id' => $consumption->id, 'feed_consumption_correction_id' => $correction?->id,
                'feed_recipe_id' => $recipeId, 'production_unit_id' => $consumption->production_unit_id,
                'delta_grams' => $delta, 'balance_before' => $before, 'balance_after' => $after, 'operation_id' => $operationId,
            ])->save();
            $this->evaluator->evaluate($balance, $correction === null ? 'consumption' : 'consumption_correction', $operationId);
            $movements[] = ['id' => $movement->id, 'recipe_id' => $recipeId, 'delta_grams' => (string) $delta, 'balance_before' => (string) $before, 'balance_after' => (string) $after];
        }

        return $movements;
    }
}
