<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\ChangeFlockStatusAction;
use App\Actions\Lots\FinalizeFlockAction;
use App\Http\Requests\Lots\ChangeFlockStatusRequest;
use App\Http\Requests\Lots\FinalizeFlockRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Lots\Flock;

final readonly class FlockStatusController
{
    public function update(ChangeFlockStatusRequest $request, Flock $flock, ChangeFlockStatusAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor()));
    }

    public function finalize(FinalizeFlockRequest $request, Flock $flock, FinalizeFlockAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor()));
    }
}
