<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\ChangePoultryHouseStatusAction;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Http\Requests\ChangePoultryHouseStatusRequest;
use App\Modules\FarmStructure\Http\Resources\PoultryHouseResource;

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
            PoultryHouseStatus::from($request->string('estado')->toString()),
            $actor,
        ));
    }
}
