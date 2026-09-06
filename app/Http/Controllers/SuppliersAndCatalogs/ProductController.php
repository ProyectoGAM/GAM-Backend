<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateProductAction;
use App\Actions\SuppliersAndCatalogs\UpdateProductAction;
use App\Http\Requests\SuppliersAndCatalogs\ListProductsRequest;
use App\Http\Requests\SuppliersAndCatalogs\StoreProductRequest;
use App\Http\Requests\SuppliersAndCatalogs\UpdateProductRequest;
use App\Http\Requests\SuppliersAndCatalogs\ViewProductRequest;
use App\Http\Resources\SuppliersAndCatalogs\ProductResource;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Queries\SuppliersAndCatalogs\GetProductQuery;
use App\Queries\SuppliersAndCatalogs\ListProductsQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProductController
{
    public function index(ListProductsRequest $request, ListProductsQuery $query): AnonymousResourceCollection
    {
        return ProductResource::collection($query->execute(PublicInputMapper::toInternal($request->validated(), 'catalog')));
    }

    public function store(StoreProductRequest $request, CreateProductAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return (new ProductResource($action->execute(PublicInputMapper::toInternal($request->safe()->only(['sku', 'nombre', 'tipo', 'unidad_base', 'controla_stock']), 'catalog'), $actor)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewProductRequest $request, Product $producto, GetProductQuery $query): ProductResource
    {
        return new ProductResource($query->execute((int) $producto->getKey()));
    }

    public function update(UpdateProductRequest $request, Product $producto, UpdateProductAction $action): ProductResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new ProductResource($action->execute($producto, PublicInputMapper::toInternal($request->safe()->only(['sku', 'nombre', 'tipo', 'unidad_base', 'controla_stock']), 'catalog'), $actor));
    }
}
