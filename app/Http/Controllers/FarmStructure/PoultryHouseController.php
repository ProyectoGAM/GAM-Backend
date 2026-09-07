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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class PoultryHouseController
{
    public function index(
        ListPoultryHousesRequest $request,
        ProductionUnit $productionUnit,
        ListPoultryHousesQuery $query,
    ): AnonymousResourceCollection {
        return PoultryHouseResource::collection(
            $query->execute($productionUnit, $request->validated()),
        );
    }

    public function store(
        StorePoultryHouseRequest $request,
        ProductionUnit $productionUnit,
        CreatePoultryHouseAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->safe()->only(['name', 'bird_capacity']);
        $data['bird_capacity'] = (int) $data['bird_capacity'];
        $poultryHouse = $action->execute($productionUnit, $data, $actor);

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
        $data = $request->safe()->only(['name', 'bird_capacity']);

        if (array_key_exists('bird_capacity', $data)) {
            $data['bird_capacity'] = (int) $data['bird_capacity'];
        }

        return new PoultryHouseResource($action->execute($poultryHouse, $data, $actor));
    }
}
