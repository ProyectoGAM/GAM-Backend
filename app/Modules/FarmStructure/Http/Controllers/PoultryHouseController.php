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
