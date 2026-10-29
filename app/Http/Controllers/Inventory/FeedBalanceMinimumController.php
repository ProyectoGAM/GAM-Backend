<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\SetFeedBalanceMinimumAction;
use App\Http\Requests\Inventory\SetFeedBalanceMinimumRequest;
use App\Http\Resources\Inventory\FeedBalanceResource;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedRecipe;

final readonly class FeedBalanceMinimumController
{
    public function update(
        SetFeedBalanceMinimumRequest $request,
        ProductionUnit $productionUnit,
        FeedRecipe $recipe,
        SetFeedBalanceMinimumAction $action,
    ): FeedBalanceResource {
        return new FeedBalanceResource($action->execute(
            (int) $recipe->getKey(), (int) $productionUnit->getKey(), $request->validated('minimo_g'), $request->actor(),
        ));
    }
}
