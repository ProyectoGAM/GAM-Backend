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
    public function store(RedistributeFlockRequest $request, Flock $lote, RedistributeFlockAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($lote, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function reverse(ReverseRedistributionRequest $request, FlockMovement $redistribucion, ReverseRedistributionAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($redistribucion, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(200);
    }
}
