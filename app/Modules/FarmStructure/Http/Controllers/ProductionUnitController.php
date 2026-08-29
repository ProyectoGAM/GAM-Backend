<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\CreateProductionUnitAction;
use App\Modules\FarmStructure\Application\Actions\UpdateProductionUnitAction;
use App\Modules\FarmStructure\Application\Queries\GetProductionUnitQuery;
use App\Modules\FarmStructure\Application\Queries\ListProductionUnitsQuery;
use App\Modules\FarmStructure\Http\Requests\ListProductionUnitsRequest;
use App\Modules\FarmStructure\Http\Requests\StoreProductionUnitRequest;
use App\Modules\FarmStructure\Http\Requests\UpdateProductionUnitRequest;
use App\Modules\FarmStructure\Http\Requests\ViewProductionUnitRequest;
use App\Modules\FarmStructure\Http\Resources\ProductionUnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProductionUnitController
{
    public function index(
        ListProductionUnitsRequest $request,
        ListProductionUnitsQuery $query,
    ): AnonymousResourceCollection {
        return ProductionUnitResource::collection($query->execute($request->validated()));
    }

    public function store(
        StoreProductionUnitRequest $request,
        CreateProductionUnitAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->safe()->only(['locality_id', 'name', 'latitude', 'longitude', 'status']);
        $data['locality_id'] = (int) $data['locality_id'];
        $data['latitude'] = (string) $data['latitude'];
        $data['longitude'] = (string) $data['longitude'];
        $productionUnit = $action->execute($data, $actor);

        return (new ProductionUnitResource($productionUnit))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        ViewProductionUnitRequest $request,
        ProductionUnit $productionUnit,
        GetProductionUnitQuery $query,
    ): ProductionUnitResource {
        return new ProductionUnitResource($query->execute((int) $productionUnit->getKey()));
    }

    public function update(
        UpdateProductionUnitRequest $request,
        ProductionUnit $productionUnit,
        UpdateProductionUnitAction $action,
    ): ProductionUnitResource {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->safe()->only(['locality_id', 'name', 'latitude', 'longitude']);

        if (array_key_exists('locality_id', $data)) {
            $data['locality_id'] = (int) $data['locality_id'];
        }

        if (array_key_exists('latitude', $data)) {
            $data['latitude'] = (string) $data['latitude'];
        }

        if (array_key_exists('longitude', $data)) {
            $data['longitude'] = (string) $data['longitude'];
        }

        return new ProductionUnitResource($action->execute($productionUnit, $data, $actor));
    }
}
