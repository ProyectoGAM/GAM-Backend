<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\CreateStockLocationAction;
use App\Modules\Inventory\Application\Actions\UpdateStockLocationAction;
use App\Modules\Inventory\Application\Queries\GetStockLocationQuery;
use App\Modules\Inventory\Application\Queries\ListStockLocationsQuery;
use App\Modules\Inventory\Http\Requests\ListStockLocationsRequest;
use App\Modules\Inventory\Http\Requests\StoreStockLocationRequest;
use App\Modules\Inventory\Http\Requests\UpdateStockLocationRequest;
use App\Modules\Inventory\Http\Requests\ViewStockLocationRequest;
use App\Modules\Inventory\Http\Resources\StockLocationResource;
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
