<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\CorrectMortalityAction;
use App\Actions\Lots\RecordMortalityAction;
use App\Http\Requests\Lots\CancelMortalityRequest;
use App\Http\Requests\Lots\CorrectMortalityRequest;
use App\Http\Requests\Lots\ListMortalityRequest;
use App\Http\Requests\Lots\StoreMortalityRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Http\Resources\Lots\MortalityResource;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Queries\Lots\ListMortalityQuery;
use App\Services\Lots\LotsSnapshots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MortalityController
{
    public function index(ListMortalityRequest $request, ListMortalityQuery $query): AnonymousResourceCollection
    {
        return MortalityResource::collection($query->execute($request->attributesForAction()));
    }

    public function byFlock(ListMortalityRequest $request, Flock $flock, ListMortalityQuery $query): AnonymousResourceCollection
    {
        return MortalityResource::collection($query->execute($request->attributesForAction(), $flock));
    }

    public function show(ListMortalityRequest $request, MortalityRecord $mortality, LotsSnapshots $snapshots): MortalityResource
    {
        return new MortalityResource($snapshots->mortality($mortality, $mortality->flock));
    }

    public function store(StoreMortalityRequest $request, Flock $flock, RecordMortalityAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function update(CorrectMortalityRequest $request, MortalityRecord $mortality, CorrectMortalityAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($mortality, $request->attributesForAction(), $request->actor()));
    }

    public function cancel(CancelMortalityRequest $request, MortalityRecord $mortality, CorrectMortalityAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($mortality, $request->attributesForAction(), $request->actor(), cancel: true));
    }
}
