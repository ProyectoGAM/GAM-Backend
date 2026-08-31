<?php

namespace App\Modules\Inventory\Application\PublicApi\Queries;

use App\Models\Inventory\StockBalance;
use App\Modules\Inventory\Application\PublicApi\Data\StockAvailabilityData;

final readonly class GetStockAvailabilityQuery
{
    public function execute(int $productId, int $stockLocationId): ?StockAvailabilityData
    {
        $balance = StockBalance::query()
            ->where('product_id', $productId)
            ->where('stock_location_id', $stockLocationId)
            ->first();

        if ($balance === null) {
            return null;
        }

        return new StockAvailabilityData(
            productId: $productId,
            stockLocationId: $stockLocationId,
            availableQuantity: (string) $balance->available_quantity,
        );
    }
}
