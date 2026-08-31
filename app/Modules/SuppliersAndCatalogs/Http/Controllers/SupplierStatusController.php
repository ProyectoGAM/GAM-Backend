<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Controllers;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\SuppliersAndCatalogs\Application\Actions\ChangeSupplierStatusAction;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use App\Modules\SuppliersAndCatalogs\Http\Requests\ChangeSupplierStatusRequest;
use App\Modules\SuppliersAndCatalogs\Http\Resources\SupplierResource;

final readonly class SupplierStatusController
{
    public function update(ChangeSupplierStatusRequest $request, Supplier $proveedor, ChangeSupplierStatusAction $action): SupplierResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new SupplierResource($action->execute($proveedor, SupplierStatus::from($request->string('estado')->toString()), $actor));
    }
}
