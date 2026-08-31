<?php

namespace App\Modules\Inventory\Application\PublicApi\Data;

final readonly class StockAvailabilityData
{
    public function __construct(
        public int $productId,
        public int $stockLocationId,
        public string $availableQuantity,
    ) {}
}
