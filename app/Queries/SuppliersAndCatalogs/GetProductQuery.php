<?php

namespace App\Queries\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Product;

final readonly class GetProductQuery
{
    public function execute(int $productId): Product
    {
        return Product::query()->whereKey($productId)->firstOrFail();
    }
}
