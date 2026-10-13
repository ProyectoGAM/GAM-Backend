<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ListFeedBalancesRequest;
use App\Http\Requests\Inventory\ViewFeedOperationRequest;
use App\Http\Resources\Inventory\FeedBalanceResource;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedBalanceController extends Controller
{
    public function show(ViewFeedOperationRequest $request, ProductionUnit $productionUnit, FeedRecipe $recipe): FeedBalanceResource
    {
        $balance = FeedBalance::query()->where('feed_recipe_id', $recipe->id)->where('production_unit_id', $productionUnit->id)->first();
        if ($balance === null) {
            $balance = new FeedBalance;
            $balance->forceFill(['feed_recipe_id' => $recipe->id, 'production_unit_id' => $productionUnit->id, 'quantity_grams' => 0]);
        }
        $balance->setRelation('recipe', $recipe)->setRelation('productionUnit', $productionUnit);

        return new FeedBalanceResource($balance);
    }

    public function index(ListFeedBalancesRequest $request): AnonymousResourceCollection
    {
        $query = FeedBalance::query()->with(['recipe', 'productionUnit']);
        foreach (['receta_id' => 'feed_recipe_id', 'up_id' => 'production_unit_id'] as $public => $column) {
            if ($request->has($public)) {
                $query->where($column, $request->validated($public));
            }
        }

        return FeedBalanceResource::collection($query->orderByDesc('id')->paginate((int) $request->validated('por_pagina', 25))->withQueryString());
    }
}
