<?php

namespace App\Http\Controllers\FarmStructure;

use App\Actions\FarmStructure\ChangePoultryHouseStatusAction;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Http\Requests\FarmStructure\ChangePoultryHouseStatusRequest;
use App\Http\Resources\FarmStructure\PoultryHouseResource;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;

final readonly class PoultryHouseStatusController
{
    public function update(
        ChangePoultryHouseStatusRequest $request,
        PoultryHouse $poultryHouse,
        ChangePoultryHouseStatusAction $action,
    ): PoultryHouseResource {
        /** @var User $actor */
        $actor = $request->user();

        return new PoultryHouseResource($action->execute(
            $poultryHouse,
            PoultryHouseStatus::from($request->string('status')->toString()),
            $actor,
        ));
    }
}
