<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateStockLocationAction;
use App\Actions\Inventory\UpdateStockLocationAction;
use App\Http\Requests\Inventory\ListStockLocationsRequest;
use App\Http\Requests\Inventory\StoreStockLocationRequest;
use App\Http\Requests\Inventory\UpdateStockLocationRequest;
use App\Http\Requests\Inventory\ViewStockLocationRequest;
use App\Http\Resources\Inventory\StockLocationResource;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Queries\Inventory\GetStockLocationQuery;
use App\Queries\Inventory\ListStockLocationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class StockLocationController
{
    public function index(ListStockLocationsRequest $request, ListStockLocationsQuery $query): AnonymousResourceCollection
    {
        return StockLocationResource::collection($query->execute($request->validated()));
    }

    public function store(StoreStockLocationRequest $request, CreateStockLocationAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->safe()->only(['production_unit_id', 'name']);
        if (array_key_exists('production_unit_id', $data) && $data['production_unit_id'] !== null) {
            $data['production_unit_id'] = (int) $data['production_unit_id'];
        }

        return (new StockLocationResource($action->execute($data, $actor)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewStockLocationRequest $request, StockLocation $stockLocation, GetStockLocationQuery $query): StockLocationResource
    {
        return new StockLocationResource($query->execute((int) $stockLocation->getKey()));
    }

    public function update(UpdateStockLocationRequest $request, StockLocation $stockLocation, UpdateStockLocationAction $action): StockLocationResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockLocationResource($action->execute($stockLocation, $request->safe()->only(['production_unit_id', 'name']), $actor));
    }
}
