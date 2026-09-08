<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\ChangeVaccineStatusAction;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Http\Requests\SuppliersAndCatalogs\ChangeVaccineStatusRequest;
use App\Http\Resources\SuppliersAndCatalogs\VaccineResource;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;

final readonly class VaccineStatusController
{
    public function update(ChangeVaccineStatusRequest $request, Vaccine $vaccine, ChangeVaccineStatusAction $action): VaccineResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new VaccineResource($action->execute($vaccine, ProductStatus::from($request->string('estado')->toString()), $actor));
    }
}
