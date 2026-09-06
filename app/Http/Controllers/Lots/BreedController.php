<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\SaveBreedAction;
use App\Http\Requests\Lots\ListLotsCatalogRequest;
use App\Http\Requests\Lots\SaveBreedRequest;
use App\Http\Resources\Lots\LotsCatalogResource;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Lots\Breed;
use App\Queries\Lots\ListLotsCatalogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class BreedController
{
    public function index(ListLotsCatalogRequest $request, ListLotsCatalogQuery $query): AnonymousResourceCollection
    {
        return LotsCatalogResource::collection($query->execute(Breed::class, $request->attributesForAction()));
    }

    public function store(SaveBreedRequest $request, SaveBreedAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute(null, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function update(SaveBreedRequest $request, Breed $raza, SaveBreedAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($raza, $request->attributesForAction(), $request->actor()));
    }
}
