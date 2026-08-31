<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Modules\Lots\Application\Actions\RedistributeFlockAction;
use App\Modules\Lots\Application\Actions\ReverseRedistributionAction;
use App\Modules\Lots\Http\Requests\RedistributeFlockRequest;
use App\Modules\Lots\Http\Requests\ReverseRedistributionRequest;
use App\Modules\Lots\Http\Resources\LotsOperationResource;
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
