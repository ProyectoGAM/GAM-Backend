<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\CancelMaintenanceAction;
use App\Modules\FarmStructure\Application\Actions\CreateMaintenanceAction;
use App\Modules\FarmStructure\Application\Actions\UpdateMaintenanceAction;
use App\Modules\FarmStructure\Application\Queries\GetLatestPoultryHouseMaintenanceQuery;
use App\Modules\FarmStructure\Application\Queries\GetMaintenanceQuery;
use App\Modules\FarmStructure\Application\Queries\ListPoultryHouseMaintenancesQuery;
use App\Modules\FarmStructure\Http\Requests\CancelMaintenanceRequest;
use App\Modules\FarmStructure\Http\Requests\LatestMaintenanceRequest;
use App\Modules\FarmStructure\Http\Requests\ListMaintenancesRequest;
use App\Modules\FarmStructure\Http\Requests\StoreMaintenanceRequest;
use App\Modules\FarmStructure\Http\Requests\UpdateMaintenanceRequest;
use App\Modules\FarmStructure\Http\Requests\ViewMaintenanceRequest;
use App\Modules\FarmStructure\Http\Resources\MaintenanceResource;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MaintenanceController
{
    public function index(ListMaintenancesRequest $request, PoultryHouse $poultryHouse, ListPoultryHouseMaintenancesQuery $query): AnonymousResourceCollection
    {
        return MaintenanceResource::collection(
            $query->execute($poultryHouse, PublicInputMapper::toInternal($request->validated(), 'maintenance'))
                ->appends($request->safe()->except('pagina')),
        );
    }

    public function latest(LatestMaintenanceRequest $request, PoultryHouse $poultryHouse, GetLatestPoultryHouseMaintenanceQuery $query): MaintenanceResource|JsonResponse
    {
        $maintenance = $query->execute($poultryHouse);

        return $maintenance === null ? response()->json(['data' => null]) : new MaintenanceResource($maintenance);
    }

    public function show(ViewMaintenanceRequest $request, Maintenance $maintenance, GetMaintenanceQuery $query): MaintenanceResource
    {
        return new MaintenanceResource($query->execute($maintenance->id));
    }

    public function store(StoreMaintenanceRequest $request, PoultryHouse $poultryHouse, CreateMaintenanceAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal(
            $request->safe()->only(['fecha_mantenimiento', 'descripcion', 'costo_importe', 'costo_moneda', 'responsable_id', 'idempotency_key']),
            'maintenance',
        );
        $data['responsible_user_id'] = (int) $data['responsible_user_id'];
        $maintenance = $action->execute($poultryHouse, $data, $actor);

        return (new MaintenanceResource($maintenance))->response()->setStatusCode($maintenance->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateMaintenanceRequest $request, Maintenance $maintenance, UpdateMaintenanceAction $action): MaintenanceResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = PublicInputMapper::toInternal(
            $request->safe()->only(['fecha_mantenimiento', 'descripcion', 'costo_importe', 'costo_moneda', 'responsable_id']),
            'maintenance',
        );

        if (isset($data['responsible_user_id'])) {
            $data['responsible_user_id'] = (int) $data['responsible_user_id'];
        }

        return new MaintenanceResource($action->execute(
            $maintenance, $data, (int) $request->validated('version'), $request->validated('motivo'), $actor,
        ));
    }

    public function cancel(CancelMaintenanceRequest $request, Maintenance $maintenance, CancelMaintenanceAction $action): MaintenanceResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new MaintenanceResource($action->execute(
            $maintenance, (int) $request->validated('version'), $request->validated('motivo'), $actor,
        ));
    }
}
