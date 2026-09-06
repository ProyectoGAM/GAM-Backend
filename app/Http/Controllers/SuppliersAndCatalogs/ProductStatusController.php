<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\ChangeProductStatusAction;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Http\Requests\SuppliersAndCatalogs\ChangeProductStatusRequest;
use App\Http\Resources\SuppliersAndCatalogs\ProductResource;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;

final readonly class ProductStatusController
{
    public function update(ChangeProductStatusRequest $request, Product $product, ChangeProductStatusAction $action): ProductResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new ProductResource($action->execute($product, ProductStatus::from($request->string('status')->toString()), $actor));
    }
}
