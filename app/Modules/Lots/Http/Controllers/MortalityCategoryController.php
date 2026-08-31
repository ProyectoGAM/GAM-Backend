<?php

namespace App\Modules\Lots\Http\Controllers;

use App\Models\Lots\MortalityCategory;
use App\Modules\Lots\Application\Actions\SaveMortalityCategoryAction;
use App\Modules\Lots\Application\Queries\ListLotsCatalogQuery;
use App\Modules\Lots\Http\Requests\ListLotsCatalogRequest;
use App\Modules\Lots\Http\Requests\SaveMortalityCategoryRequest;
use App\Modules\Lots\Http\Resources\LotsCatalogResource;
use App\Modules\Lots\Http\Resources\LotsOperationResource;
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
