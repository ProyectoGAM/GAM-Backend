<?php

namespace App\Http\Controllers\FarmStructure;

use App\Actions\FarmStructure\CreateProductionUnitAction;
use App\Actions\FarmStructure\UpdateProductionUnitAction;
use App\Http\Requests\FarmStructure\ListProductionUnitsRequest;
use App\Http\Requests\FarmStructure\StoreProductionUnitRequest;
use App\Http\Requests\FarmStructure\UpdateProductionUnitRequest;
use App\Http\Requests\FarmStructure\ViewProductionUnitRequest;
use App\Http\Resources\FarmStructure\ProductionUnitResource;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Queries\FarmStructure\GetProductionUnitQuery;
use App\Queries\FarmStructure\ListProductionUnitsQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProductionUnitController
{
    public function index(
        ListProductionUnitsRequest $request,
        ListProductionUnitsQuery $query,
    ): AnonymousResourceCollection {
        return ProductionUnitResource::collection($query->execute(PublicInputMapper::toInternal($request->validated())));
    }

    public function store(
        StoreProductionUnitRequest $request,
        CreateProductionUnitAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal($request->safe()->only(['localidad_id', 'nombre', 'latitud', 'longitud', 'estado']));
        $data['locality_id'] = (int) $data['locality_id'];
        $data['latitude'] = (string) $data['latitude'];
        $data['longitude'] = (string) $data['longitude'];
        $unidadProductiva = $action->execute($data, $actor);

        return (new ProductionUnitResource($unidadProductiva))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        ViewProductionUnitRequest $request,
        ProductionUnit $unidadProductiva,
        GetProductionUnitQuery $query,
    ): ProductionUnitResource {
        return new ProductionUnitResource($query->execute((int) $unidadProductiva->getKey()));
    }

    public function update(
        UpdateProductionUnitRequest $request,
        ProductionUnit $unidadProductiva,
        UpdateProductionUnitAction $action,
    ): ProductionUnitResource {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal($request->safe()->only(['localidad_id', 'nombre', 'latitud', 'longitud']));

        if (array_key_exists('locality_id', $data)) {
            $data['locality_id'] = (int) $data['locality_id'];
        }

        if (array_key_exists('latitude', $data)) {
            $data['latitude'] = (string) $data['latitude'];
        }

        if (array_key_exists('longitude', $data)) {
            $data['longitude'] = (string) $data['longitude'];
        }

        return new ProductionUnitResource($action->execute($unidadProductiva, $data, $actor));
    }
}
