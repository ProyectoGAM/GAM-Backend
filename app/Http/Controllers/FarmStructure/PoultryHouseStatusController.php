<?php

namespace App\Http\Controllers\FarmStructure;

use App\Actions\FarmStructure\ChangePoultryHouseStatusAction;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Http\Requests\FarmStructure\ChangePoultryHouseStatusRequest;
use App\Http\Resources\FarmStructure\PoultryHouseResource;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Queries\FarmStructure\GetPoultryHouseQuery;

final readonly class PoultryHouseStatusController
{
    public function update(
        ChangePoultryHouseStatusRequest $request,
        PoultryHouse $poultryHouse,
        ChangePoultryHouseStatusAction $action,
        GetPoultryHouseQuery $query,
    ): PoultryHouseResource {
        /** @var User $actor */
        $actor = $request->user();

        $updatedPoultryHouse = $action->execute(
            $poultryHouse,
            PoultryHouseStatus::from($request->string('status')->toString()),
            $actor,
        );

        return new PoultryHouseResource($query->execute((int) $updatedPoultryHouse->getKey()));
    }
}
