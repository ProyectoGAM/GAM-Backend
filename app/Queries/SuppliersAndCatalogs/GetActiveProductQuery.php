<?php

namespace App\Queries\SuppliersAndCatalogs;

use App\DTO\SuppliersAndCatalogs\ProductData;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\SuppliersAndCatalogs\Product;

final readonly class GetActiveProductQuery
{
    public function execute(int $productId): ?ProductData
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('status', ProductStatus::Active->value)
            ->first();

        if ($product === null) {
            return null;
        }

        return new ProductData(
            id: (int) $product->getKey(),
            name: $product->name,
            kind: $product->kind,
            baseUnit: $product->base_unit,
            stockTracked: $product->stock_tracked,
            systemKey: $product->system_key,
        );
    }
}
