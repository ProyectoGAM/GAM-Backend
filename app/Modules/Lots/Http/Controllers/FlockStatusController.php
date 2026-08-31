<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\Flock;
use App\Modules\Lots\Application\Actions\ChangeFlockStatusAction;
use App\Modules\Lots\Application\Actions\FinalizeFlockAction;
use App\Modules\Lots\Http\Requests\ChangeFlockStatusRequest;
use App\Modules\Lots\Http\Requests\FinalizeFlockRequest;
use App\Modules\Lots\Http\Resources\LotsOperationResource;

final readonly class FlockStatusController
{
    public function update(ChangeFlockStatusRequest $request, Flock $lote, ChangeFlockStatusAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($lote, $request->attributesForAction(), $request->actor()));
    }

    public function finalize(FinalizeFlockRequest $request, Flock $lote, FinalizeFlockAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($lote, $request->attributesForAction(), $request->actor()));
    }
}
