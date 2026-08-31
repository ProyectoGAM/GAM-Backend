<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Modules\Lots\Application\Actions\CorrectMortalityAction;
use App\Modules\Lots\Application\Actions\RecordMortalityAction;
use App\Modules\Lots\Application\Queries\ListMortalityQuery;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Http\Requests\CancelMortalityRequest;
use App\Modules\Lots\Http\Requests\CorrectMortalityRequest;
use App\Modules\Lots\Http\Requests\ListMortalityRequest;
use App\Modules\Lots\Http\Requests\StoreMortalityRequest;
use App\Modules\Lots\Http\Resources\LotsOperationResource;
use App\Modules\Lots\Http\Resources\MortalityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MortalityController
{
    public function index(ListMortalityRequest $request, ListMortalityQuery $query): AnonymousResourceCollection
    {
        return MortalityResource::collection($query->execute($request->attributesForAction()));
    }

    public function byFlock(ListMortalityRequest $request, Flock $lote, ListMortalityQuery $query): AnonymousResourceCollection
    {
        return MortalityResource::collection($query->execute($request->attributesForAction(), $lote));
    }

    public function show(ListMortalityRequest $request, MortalityRecord $mortalidad, LotsSnapshots $snapshots): MortalityResource
    {
        return new MortalityResource($snapshots->mortality($mortalidad, $mortalidad->flock));
    }

    public function store(StoreMortalityRequest $request, Flock $lote, RecordMortalityAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($lote, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function update(CorrectMortalityRequest $request, MortalityRecord $mortalidad, CorrectMortalityAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($mortalidad, $request->attributesForAction(), $request->actor()));
    }

    public function cancel(CancelMortalityRequest $request, MortalityRecord $mortalidad, CorrectMortalityAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($mortalidad, $request->attributesForAction(), $request->actor(), cancel: true));
    }
}
