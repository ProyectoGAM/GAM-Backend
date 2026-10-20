<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustFeedBalanceAction;
use App\Actions\Inventory\AdjustFeedIngredientAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustFeedStockRequest;
use App\Http\Requests\Inventory\ListFeedOperationsRequest;
use App\Http\Resources\Inventory\FeedStockOperationResource;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedRecipe;
use App\Models\SuppliersAndCatalogs\Product;
use App\Queries\Inventory\ListFeedOperationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedAdjustmentController extends Controller
{
    public function index(ListFeedOperationsRequest $request, ListFeedOperationsQuery $query): AnonymousResourceCollection
    {
        return FeedStockOperationResource::collection($query->execute($request->validated(), true));
    }

    public function ingredient(AdjustFeedStockRequest $request, PoultryHouse $poultryHouse, Product $product, AdjustFeedIngredientAction $action): JsonResponse
    {
        $operation = $action->execute((int) $poultryHouse->id, (int) $product->id,
            $request->validated('accion'), $request->validated('valor_g'), $request->validated('motivo'),
            $request->validated('idempotency_key'), $request->actor());

        return (new FeedStockOperationResource($operation))->response()->setStatusCode(201);
    }

    public function feed(AdjustFeedStockRequest $request, ProductionUnit $productionUnit, FeedRecipe $recipe, AdjustFeedBalanceAction $action): JsonResponse
    {
        $operation = $action->execute((int) $recipe->id, (int) $productionUnit->id,
            $request->validated('accion'), $request->validated('valor_g'), $request->validated('motivo'),
            $request->validated('idempotency_key'), $request->actor());

        return (new FeedStockOperationResource($operation))->response()->setStatusCode(201);
    }
}
