<?php

namespace App\DTO\Inventory;

final readonly class StockAvailabilityData
{
    public function __construct(
        public int $productId,
        public int $stockLocationId,
        public string $availableQuantity,
    ) {}
}
