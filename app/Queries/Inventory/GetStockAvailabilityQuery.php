<?php

namespace App\Queries\Inventory;

use App\DTO\Inventory\StockAvailabilityData;
use App\Models\Inventory\StockBalance;

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
