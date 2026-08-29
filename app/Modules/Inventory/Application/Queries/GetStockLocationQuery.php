<?php

namespace App\Modules\Inventory\Application\Queries;

use App\Models\Inventory\StockLocation;

final readonly class GetStockLocationQuery
{
    public function execute(int $stockLocationId): StockLocation
    {
        return StockLocation::query()
            ->with('productionUnit')
            ->whereKey($stockLocationId)
            ->firstOrFail();
    }
}
