<?php

namespace App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries;

use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Data\ProductData;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;

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
