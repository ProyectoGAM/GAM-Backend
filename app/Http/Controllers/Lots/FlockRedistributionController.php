<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\RedistributeFlockAction;
use App\Actions\Lots\ReverseRedistributionAction;
use App\Http\Requests\Lots\RedistributeFlockRequest;
use App\Http\Requests\Lots\ReverseRedistributionRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use Illuminate\Http\JsonResponse;

final readonly class FlockRedistributionController
{
    public function store(RedistributeFlockRequest $request, Flock $flock, RedistributeFlockAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function reverse(ReverseRedistributionRequest $request, FlockMovement $redistribution, ReverseRedistributionAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($redistribution, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(200);
    }
}
