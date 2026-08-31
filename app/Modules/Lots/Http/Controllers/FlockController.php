<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\Flock;
use App\Modules\Lots\Application\Actions\CreateFlockAction;
use App\Modules\Lots\Application\Actions\UpdateFlockAction;
use App\Modules\Lots\Application\Queries\GetFlockHistoryQuery;
use App\Modules\Lots\Application\Queries\ListFlocksQuery;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Http\Requests\ListFlockHistoryRequest;
use App\Modules\Lots\Http\Requests\ListFlocksRequest;
use App\Modules\Lots\Http\Requests\StoreFlockRequest;
use App\Modules\Lots\Http\Requests\UpdateFlockRequest;
use App\Modules\Lots\Http\Requests\ViewFlockRequest;
use App\Modules\Lots\Http\Resources\FlockMovementResource;
use App\Modules\Lots\Http\Resources\FlockResource;
use App\Modules\Lots\Http\Resources\LotsOperationResource;
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

    public function show(ViewFlockRequest $request, Flock $lote, LotsSnapshots $snapshots): FlockResource
    {
        return new FlockResource($snapshots->flock($lote));
    }

    public function update(UpdateFlockRequest $request, Flock $lote, UpdateFlockAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($lote, $request->attributesForAction(), $request->actor()));
    }

    public function history(ListFlockHistoryRequest $request, Flock $lote, GetFlockHistoryQuery $query): AnonymousResourceCollection
    {
        return FlockMovementResource::collection($query->execute($lote, $request->attributesForAction()));
    }
}
