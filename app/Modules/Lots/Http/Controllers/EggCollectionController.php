<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Modules\Lots\Application\Actions\CorrectEggCollectionAction;
use App\Modules\Lots\Application\Actions\RecordEggCollectionAction;
use App\Modules\Lots\Application\Queries\GetEggProductionMetricsQuery;
use App\Modules\Lots\Application\Queries\GetFlockMetricsQuery;
use App\Modules\Lots\Application\Queries\ListEggCollectionsQuery;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Http\Requests\CancelEggCollectionRequest;
use App\Modules\Lots\Http\Requests\CorrectEggCollectionRequest;
use App\Modules\Lots\Http\Requests\FlockMetricsRequest;
use App\Modules\Lots\Http\Requests\ListEggCollectionsRequest;
use App\Modules\Lots\Http\Requests\StoreEggCollectionRequest;
use App\Modules\Lots\Http\Resources\EggCollectionResource;
use App\Modules\Lots\Http\Resources\LotsOperationResource;
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
