<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\ChangeSupplierStatusAction;
use App\Enums\SuppliersAndCatalogs\SupplierStatus;
use App\Http\Requests\SuppliersAndCatalogs\ChangeSupplierStatusRequest;
use App\Http\Resources\SuppliersAndCatalogs\SupplierResource;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;

final readonly class SupplierStatusController
{
    public function update(ChangeSupplierStatusRequest $request, Supplier $supplier, ChangeSupplierStatusAction $action): SupplierResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new SupplierResource($action->execute($supplier, SupplierStatus::from($request->string('status')->toString()), $actor));
    }
}
