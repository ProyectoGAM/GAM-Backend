<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Controllers;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\SuppliersAndCatalogs\Application\Actions\CreateSupplierAction;
use App\Modules\SuppliersAndCatalogs\Application\Actions\UpdateSupplierAction;
use App\Modules\SuppliersAndCatalogs\Application\Queries\GetSupplierQuery;
use App\Modules\SuppliersAndCatalogs\Application\Queries\ListSuppliersQuery;
use App\Modules\SuppliersAndCatalogs\Http\Requests\ListSuppliersRequest;
use App\Modules\SuppliersAndCatalogs\Http\Requests\StoreSupplierRequest;
use App\Modules\SuppliersAndCatalogs\Http\Requests\UpdateSupplierRequest;
use App\Modules\SuppliersAndCatalogs\Http\Requests\ViewSupplierRequest;
use App\Modules\SuppliersAndCatalogs\Http\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class SupplierController
{
    public function index(ListSuppliersRequest $request, ListSuppliersQuery $query): AnonymousResourceCollection
    {
        return SupplierResource::collection($query->execute($request->validated()));
    }

    public function store(StoreSupplierRequest $request, CreateSupplierAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->safe()->only(['locality_id', 'name', 'address']);
        if (array_key_exists('locality_id', $data) && $data['locality_id'] !== null) {
            $data['locality_id'] = (int) $data['locality_id'];
        }

        return (new SupplierResource($action->execute($data, $actor)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewSupplierRequest $request, Supplier $supplier, GetSupplierQuery $query): SupplierResource
    {
        return new SupplierResource($query->execute((int) $supplier->getKey()));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier, UpdateSupplierAction $action): SupplierResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new SupplierResource($action->execute($supplier, $request->safe()->only(['locality_id', 'name', 'address']), $actor));
    }
}
