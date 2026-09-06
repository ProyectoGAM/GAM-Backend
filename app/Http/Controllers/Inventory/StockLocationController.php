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
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class StockLocationController
{
    public function index(ListStockLocationsRequest $request, ListStockLocationsQuery $query): AnonymousResourceCollection
    {
        return StockLocationResource::collection($query->execute(PublicInputMapper::toInternal($request->validated(), 'inventory')));
    }

    public function store(StoreStockLocationRequest $request, CreateStockLocationAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal($request->safe()->only(['unidad_productiva_id', 'nombre']));
        if (array_key_exists('production_unit_id', $data) && $data['production_unit_id'] !== null) {
            $data['production_unit_id'] = (int) $data['production_unit_id'];
        }

        return (new StockLocationResource($action->execute($data, $actor)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewStockLocationRequest $request, StockLocation $ubicacionStock, GetStockLocationQuery $query): StockLocationResource
    {
        return new StockLocationResource($query->execute((int) $ubicacionStock->getKey()));
    }

    public function update(UpdateStockLocationRequest $request, StockLocation $ubicacionStock, UpdateStockLocationAction $action): StockLocationResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockLocationResource($action->execute($ubicacionStock, PublicInputMapper::toInternal($request->safe()->only(['unidad_productiva_id', 'nombre'])), $actor));
    }
}
