<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\CreatePoultryHouseAction;
use App\Modules\FarmStructure\Application\Actions\UpdatePoultryHouseAction;
use App\Modules\FarmStructure\Application\Queries\GetPoultryHouseQuery;
use App\Modules\FarmStructure\Application\Queries\ListPoultryHousesQuery;
use App\Modules\FarmStructure\Http\Requests\ListPoultryHousesRequest;
use App\Modules\FarmStructure\Http\Requests\StorePoultryHouseRequest;
use App\Modules\FarmStructure\Http\Requests\UpdatePoultryHouseRequest;
use App\Modules\FarmStructure\Http\Requests\ViewPoultryHouseRequest;
use App\Modules\FarmStructure\Http\Resources\PoultryHouseResource;
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
