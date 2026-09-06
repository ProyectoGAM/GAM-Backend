<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\CorrectEggCollectionAction;
use App\Actions\Lots\RecordEggCollectionAction;
use App\Http\Requests\Lots\CancelEggCollectionRequest;
use App\Http\Requests\Lots\CorrectEggCollectionRequest;
use App\Http\Requests\Lots\FlockMetricsRequest;
use App\Http\Requests\Lots\ListEggCollectionsRequest;
use App\Http\Requests\Lots\StoreEggCollectionRequest;
use App\Http\Resources\Lots\EggCollectionResource;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Queries\Lots\GetEggProductionMetricsQuery;
use App\Queries\Lots\GetFlockMetricsQuery;
use App\Queries\Lots\ListEggCollectionsQuery;
use App\Services\Lots\LotsSnapshots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class EggCollectionController
{
    public function index(ListEggCollectionsRequest $request, Flock $lote, ListEggCollectionsQuery $query): AnonymousResourceCollection
    {
        return EggCollectionResource::collection($query->execute($request->attributesForAction(), $lote));
    }

    public function indexAll(ListEggCollectionsRequest $request, ListEggCollectionsQuery $query): AnonymousResourceCollection
    {
        return EggCollectionResource::collection($query->execute($request->attributesForAction()));
    }

    public function show(ListEggCollectionsRequest $request, EggCollection $recoleccion, LotsSnapshots $snapshots): EggCollectionResource
    {
        return new EggCollectionResource($snapshots->collection($recoleccion, $recoleccion->flock));
    }

    public function store(StoreEggCollectionRequest $request, Flock $lote, RecordEggCollectionAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($lote, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function update(CorrectEggCollectionRequest $request, EggCollection $recoleccion, CorrectEggCollectionAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($recoleccion, $request->attributesForAction(), $request->actor()));
    }

    public function cancel(CancelEggCollectionRequest $request, EggCollection $recoleccion, CorrectEggCollectionAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($recoleccion, $request->attributesForAction(), $request->actor(), cancel: true));
    }

    public function metrics(FlockMetricsRequest $request, Flock $lote, GetFlockMetricsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($lote, $request->attributesForAction())]);
    }

    public function metricsAll(FlockMetricsRequest $request, GetEggProductionMetricsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($request->attributesForAction())]);
    }
}
