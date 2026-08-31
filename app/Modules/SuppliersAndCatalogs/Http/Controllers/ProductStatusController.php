<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Controllers;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Modules\SuppliersAndCatalogs\Application\Actions\ChangeProductStatusAction;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use App\Modules\SuppliersAndCatalogs\Http\Requests\ChangeProductStatusRequest;
use App\Modules\SuppliersAndCatalogs\Http\Resources\ProductResource;

final readonly class ProductStatusController
{
    public function update(ChangeProductStatusRequest $request, Product $producto, ChangeProductStatusAction $action): ProductResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new ProductResource($action->execute($producto, ProductStatus::from($request->string('estado')->toString()), $actor));
    }
}
