<?php

namespace App\Services\Inventory;

use App\Events\Inventory\FeedBalanceEvaluated;
use App\Models\Inventory\FeedBalance;

final readonly class FeedBalanceEvaluator
{
    /** Publica el resultado sólo después del commit; las notificaciones y su deduplicación pertenecen a Alertas. */
    public function evaluate(FeedBalance $balance, string $reason, string $operationId): void
    {
        event(new FeedBalanceEvaluated(
            balanceId: (int) $balance->getKey(),
            recipeId: (int) $balance->feed_recipe_id,
            productionUnitId: (int) $balance->production_unit_id,
            quantityGrams: $balance->quantity_grams,
            minimumQuantityGrams: $balance->minimum_quantity_grams,
            status: $balance->stockStatus(),
            reason: $reason,
            operationId: $operationId,
        ));
    }
}
