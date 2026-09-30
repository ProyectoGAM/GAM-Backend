<?php

namespace App\Http\Controllers\FarmStructure;

use App\Actions\FarmStructure\ChangeProductionUnitStatusAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Http\Requests\FarmStructure\ChangeProductionUnitStatusRequest;
use App\Http\Resources\FarmStructure\ProductionUnitResource;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;

final readonly class ProductionUnitStatusController
{
    public function update(
        ChangeProductionUnitStatusRequest $request,
        ProductionUnit $productionUnit,
        ChangeProductionUnitStatusAction $action,
    ): ProductionUnitResource {
        /** @var User $actor */
        $actor = $request->user();

        return new ProductionUnitResource($action->execute(
            $productionUnit,
            ProductionUnitStatus::from($request->string('status')->toString()),
            $actor,
        ));
    }
}
