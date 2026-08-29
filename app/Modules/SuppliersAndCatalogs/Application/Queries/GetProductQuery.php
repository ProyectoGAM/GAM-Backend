<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Queries;

use App\Models\SuppliersAndCatalogs\Product;

final readonly class GetProductQuery
{
    public function execute(int $productId): Product
    {
        return Product::query()->whereKey($productId)->firstOrFail();
    }
}
