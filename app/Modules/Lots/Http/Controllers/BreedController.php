<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\Breed;
use App\Modules\Lots\Application\Actions\SaveBreedAction;
use App\Modules\Lots\Application\Queries\ListLotsCatalogQuery;
use App\Modules\Lots\Http\Requests\ListLotsCatalogRequest;
use App\Modules\Lots\Http\Requests\SaveBreedRequest;
use App\Modules\Lots\Http\Resources\LotsCatalogResource;
use App\Modules\Lots\Http\Resources\LotsOperationResource;
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
