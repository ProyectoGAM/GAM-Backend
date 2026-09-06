<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateSupplierAction;
use App\Actions\SuppliersAndCatalogs\UpdateSupplierAction;
use App\Http\Requests\SuppliersAndCatalogs\ListSuppliersRequest;
use App\Http\Requests\SuppliersAndCatalogs\StoreSupplierRequest;
use App\Http\Requests\SuppliersAndCatalogs\UpdateSupplierRequest;
use App\Http\Requests\SuppliersAndCatalogs\ViewSupplierRequest;
use App\Http\Resources\SuppliersAndCatalogs\SupplierResource;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Queries\SuppliersAndCatalogs\GetSupplierQuery;
use App\Queries\SuppliersAndCatalogs\ListSuppliersQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class SupplierController
{
    public function index(ListSuppliersRequest $request, ListSuppliersQuery $query): AnonymousResourceCollection
    {
        return SupplierResource::collection($query->execute(PublicInputMapper::toInternal($request->validated())));
    }

    public function store(StoreSupplierRequest $request, CreateSupplierAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal($request->safe()->only(['localidad_id', 'nombre', 'direccion']));
        if (array_key_exists('locality_id', $data) && $data['locality_id'] !== null) {
            $data['locality_id'] = (int) $data['locality_id'];
        }

        return (new SupplierResource($action->execute($data, $actor)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewSupplierRequest $request, Supplier $proveedor, GetSupplierQuery $query): SupplierResource
    {
        return new SupplierResource($query->execute((int) $proveedor->getKey()));
    }

    public function update(UpdateSupplierRequest $request, Supplier $proveedor, UpdateSupplierAction $action): SupplierResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new SupplierResource($action->execute($proveedor, PublicInputMapper::toInternal($request->safe()->only(['localidad_id', 'nombre', 'direccion'])), $actor));
    }
}
