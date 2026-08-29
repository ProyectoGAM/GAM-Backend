<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\ChangeProductionUnitStatusAction;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\FarmStructure\Http\Requests\ChangeProductionUnitStatusRequest;
use App\Modules\FarmStructure\Http\Resources\ProductionUnitResource;

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
