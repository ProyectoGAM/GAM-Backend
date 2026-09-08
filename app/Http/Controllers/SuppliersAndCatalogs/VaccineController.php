<?php

namespace App\Http\Controllers\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateVaccineAction;
use App\Actions\SuppliersAndCatalogs\UpdateVaccineAction;
use App\Http\Requests\SuppliersAndCatalogs\ListVaccinesRequest;
use App\Http\Requests\SuppliersAndCatalogs\StoreVaccineRequest;
use App\Http\Requests\SuppliersAndCatalogs\UpdateVaccineRequest;
use App\Http\Requests\SuppliersAndCatalogs\ViewVaccineRequest;
use App\Http\Resources\SuppliersAndCatalogs\VaccineResource;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use App\Queries\SuppliersAndCatalogs\ListVaccinesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class VaccineController
{
    public function index(ListVaccinesRequest $request, ListVaccinesQuery $query): AnonymousResourceCollection
    {
        $filters = $request->validated();
        foreach (['proveedor_id', 'page', 'per_page'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return VaccineResource::collection($query->execute($filters));
    }

    public function store(StoreVaccineRequest $request, CreateVaccineAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $vaccine = $action->execute([
            'sku' => $data['sku'],
            'name' => $data['nombre'],
            'description' => $data['descripcion'],
            'details' => $data['detalles'] ?? null,
            'supplier_id' => (int) $data['proveedor_id'],
            'base_unit' => $data['unidad_base'] ?? null,
            'idempotency_key' => $data['idempotency_key'],
        ], $actor);

        return (new VaccineResource($vaccine))->response()->setStatusCode($vaccine->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(ViewVaccineRequest $request, Vaccine $vaccine): VaccineResource
    {
        return new VaccineResource($vaccine->load(['product', 'supplier']));
    }

    public function update(UpdateVaccineRequest $request, Vaccine $vaccine, UpdateVaccineAction $action): VaccineResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        return new VaccineResource($action->execute($vaccine, [
            ...(array_key_exists('nombre', $data) ? ['name' => $data['nombre']] : []),
            ...(array_key_exists('descripcion', $data) ? ['description' => $data['descripcion']] : []),
            ...(array_key_exists('detalles', $data) ? ['details' => $data['detalles']] : []),
            ...(array_key_exists('proveedor_id', $data) ? ['supplier_id' => (int) $data['proveedor_id']] : []),
        ], $actor));
    }
}
