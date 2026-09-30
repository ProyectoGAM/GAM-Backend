<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateFeedIngredientAction;
use App\Http\Requests\Inventory\StoreFeedIngredientRequest;
use App\Http\Requests\Inventory\ViewFeedStockRequest;
use App\Http\Resources\Inventory\FeedStockResource;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Queries\Inventory\GetFeedStockQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class FeedStockController
{
    public function store(
        StoreFeedIngredientRequest $request,
        PoultryHouse $poultryHouse,
        CreateFeedIngredientAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $result = $action->execute($poultryHouse, $request->validated(), $actor);

        return (new FeedStockResource($result))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function byHouse(
        ViewFeedStockRequest $request,
        PoultryHouse $poultryHouse,
        GetFeedStockQuery $query,
    ): FeedStockResource {
        return new FeedStockResource($query->execute($poultryHouse));
    }

    public function byProductionUnit(
        ViewFeedStockRequest $request,
        ProductionUnit $productionUnit,
        GetFeedStockQuery $query,
    ): FeedStockResource {
        return new FeedStockResource($query->execute(productionUnit: $productionUnit));
    }

    public function global(ViewFeedStockRequest $request, GetFeedStockQuery $query): FeedStockResource
    {
        return new FeedStockResource($query->execute());
    }
}
