<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\SaveEggPresentationAction;
use App\Http\Requests\Inventory\ListEggPresentationsRequest;
use App\Http\Requests\Inventory\StoreEggPresentationRequest;
use App\Http\Requests\Inventory\UpdateEggPresentationRequest;
use App\Http\Resources\Inventory\EggPresentationResource;
use App\Models\Inventory\EggPresentation;
use App\Services\Inventory\EggPresentationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class EggPresentationController
{
    public function index(ListEggPresentationsRequest $request, EggPresentationCatalog $catalog): AnonymousResourceCollection
    {
        return EggPresentationResource::collection(EggPresentation::query()->orderBy('name')->orderBy('id')->get())
            ->additional(['meta' => ['locked' => $catalog->isLocked()]]);
    }

    public function store(StoreEggPresentationRequest $request, SaveEggPresentationAction $action): JsonResponse
    {
        return (new EggPresentationResource($action->execute(null, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateEggPresentationRequest $request, EggPresentation $presentation, SaveEggPresentationAction $action): EggPresentationResource
    {
        return new EggPresentationResource($action->execute($presentation, $request->validated(), $request->user()));
    }
}
