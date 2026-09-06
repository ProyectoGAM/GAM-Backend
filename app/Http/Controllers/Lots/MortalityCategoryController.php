<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\SaveMortalityCategoryAction;
use App\Http\Requests\Lots\ListLotsCatalogRequest;
use App\Http\Requests\Lots\SaveMortalityCategoryRequest;
use App\Http\Resources\Lots\LotsCatalogResource;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Lots\MortalityCategory;
use App\Queries\Lots\ListLotsCatalogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MortalityCategoryController
{
    public function index(ListLotsCatalogRequest $request, ListLotsCatalogQuery $query): AnonymousResourceCollection
    {
        return LotsCatalogResource::collection($query->execute(MortalityCategory::class, $request->attributesForAction()));
    }

    public function store(SaveMortalityCategoryRequest $request, SaveMortalityCategoryAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute(null, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function update(SaveMortalityCategoryRequest $request, MortalityCategory $categoria, SaveMortalityCategoryAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($categoria, $request->attributesForAction(), $request->actor()));
    }
}
