<?php

namespace App\Http\Controllers\FarmStructure;

use App\Actions\FarmStructure\CreatePoultryHouseAction;
use App\Actions\FarmStructure\UpdatePoultryHouseAction;
use App\Http\Requests\FarmStructure\ListPoultryHousesRequest;
use App\Http\Requests\FarmStructure\StorePoultryHouseRequest;
use App\Http\Requests\FarmStructure\UpdatePoultryHouseRequest;
use App\Http\Requests\FarmStructure\ViewPoultryHouseRequest;
use App\Http\Resources\FarmStructure\PoultryHouseResource;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Queries\FarmStructure\GetPoultryHouseQuery;
use App\Queries\FarmStructure\ListPoultryHousesQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class PoultryHouseController
{
    public function index(
        ListPoultryHousesRequest $request,
        ProductionUnit $unidadProductiva,
        ListPoultryHousesQuery $query,
    ): AnonymousResourceCollection {
        return PoultryHouseResource::collection(
            $query->execute($unidadProductiva, PublicInputMapper::toInternal($request->validated())),
        );
    }

    public function store(
        StorePoultryHouseRequest $request,
        ProductionUnit $unidadProductiva,
        CreatePoultryHouseAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal($request->safe()->only(['nombre', 'capacidad_aves']));
        $data['bird_capacity'] = (int) $data['bird_capacity'];
        $poultryHouse = $action->execute($unidadProductiva, $data, $actor);

        return (new PoultryHouseResource($poultryHouse))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        ViewPoultryHouseRequest $request,
        PoultryHouse $poultryHouse,
        GetPoultryHouseQuery $query,
    ): PoultryHouseResource {
        return new PoultryHouseResource($query->execute((int) $poultryHouse->getKey()));
    }

    public function update(
        UpdatePoultryHouseRequest $request,
        PoultryHouse $poultryHouse,
        UpdatePoultryHouseAction $action,
    ): PoultryHouseResource {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal($request->safe()->only(['nombre', 'capacidad_aves']));

        if (array_key_exists('bird_capacity', $data)) {
            $data['bird_capacity'] = (int) $data['bird_capacity'];
        }

        return new PoultryHouseResource($action->execute($poultryHouse, $data, $actor));
    }
}
