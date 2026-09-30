<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\ChangeProductStatusAction;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Http\Requests\SuppliersAndCatalogs\ChangeProductStatusRequest;
use App\Http\Resources\SuppliersAndCatalogs\ProductResource;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Queries\SuppliersAndCatalogs\GetProductQuery;

final readonly class ProductStatusController
{
    public function update(ChangeProductStatusRequest $request, Product $product, ChangeProductStatusAction $action, GetProductQuery $query): ProductResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $action->execute($product, ProductStatus::from($request->string('status')->toString()), $actor);

        return new ProductResource($query->execute((int) $updated->getKey()));
    }
}
