<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateMedicineAction;
use App\Http\Requests\SuppliersAndCatalogs\ListMedicinesRequest;
use App\Http\Requests\SuppliersAndCatalogs\StoreMedicineRequest;
use App\Http\Resources\SuppliersAndCatalogs\MedicineResource;
use App\Models\User;
use App\Queries\SuppliersAndCatalogs\ListMedicinesQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MedicineController
{
    public function index(ListMedicinesRequest $request, ListMedicinesQuery $query): AnonymousResourceCollection
    {
        $filters = PublicInputMapper::toInternal($request->validated());
        foreach (['supplier_id', 'page', 'per_page'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return MedicineResource::collection($query->execute($filters));
    }

    public function store(StoreMedicineRequest $request, CreateMedicineAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        return (new MedicineResource($action->execute([
            'name' => $data['nombre'],
            'description' => $data['descripcion'],
            'supplier_id' => (int) $data['proveedor_id'],
            'idempotency_key' => $data['idempotency_key'],
        ], $actor)))->response()->setStatusCode(201);
    }
}
