<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\CreateFlockAction;
use App\Actions\Lots\UpdateFlockAction;
use App\Http\Requests\Lots\ListFlockHistoryRequest;
use App\Http\Requests\Lots\ListFlocksRequest;
use App\Http\Requests\Lots\StoreFlockRequest;
use App\Http\Requests\Lots\UpdateFlockRequest;
use App\Http\Requests\Lots\ViewFlockRequest;
use App\Http\Resources\Lots\FlockMovementResource;
use App\Http\Resources\Lots\FlockResource;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Lots\Flock;
use App\Queries\Lots\GetFlockHistoryQuery;
use App\Queries\Lots\ListFlocksQuery;
use App\Services\Lots\LotsSnapshots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class FlockController
{
    public function index(ListFlocksRequest $request, ListFlocksQuery $query): AnonymousResourceCollection
    {
        return FlockResource::collection($query->execute($request->attributesForAction()));
    }

    public function store(StoreFlockRequest $request, CreateFlockAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function show(ViewFlockRequest $request, Flock $flock, LotsSnapshots $snapshots): FlockResource
    {
        return new FlockResource($snapshots->flock($flock));
    }

    public function update(UpdateFlockRequest $request, Flock $flock, UpdateFlockAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor()));
    }

    public function history(ListFlockHistoryRequest $request, Flock $flock, GetFlockHistoryQuery $query): AnonymousResourceCollection
    {
        return FlockMovementResource::collection($query->execute($flock, $request->attributesForAction()));
    }
}
