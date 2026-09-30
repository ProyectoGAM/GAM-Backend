<?php

namespace App\Http\Controllers\FarmStructure;

use App\Actions\FarmStructure\CancelMaintenanceAction;
use App\Actions\FarmStructure\CreateMaintenanceAction;
use App\Actions\FarmStructure\UpdateMaintenanceAction;
use App\Http\Requests\FarmStructure\CancelMaintenanceRequest;
use App\Http\Requests\FarmStructure\LatestMaintenanceRequest;
use App\Http\Requests\FarmStructure\ListMaintenancesRequest;
use App\Http\Requests\FarmStructure\StoreMaintenanceRequest;
use App\Http\Requests\FarmStructure\UpdateMaintenanceRequest;
use App\Http\Requests\FarmStructure\ViewMaintenanceRequest;
use App\Http\Resources\FarmStructure\MaintenanceResource;
use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Queries\FarmStructure\GetLatestPoultryHouseMaintenanceQuery;
use App\Queries\FarmStructure\GetMaintenanceQuery;
use App\Queries\FarmStructure\ListPoultryHouseMaintenancesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class MaintenanceController
{
    public function index(ListMaintenancesRequest $request, PoultryHouse $poultryHouse, ListPoultryHouseMaintenancesQuery $query): AnonymousResourceCollection
    {
        return MaintenanceResource::collection(
            $query->execute($poultryHouse, $request->validated())
                ->appends($request->safe()->except('page')),
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
        $data = $request->safe()->only(['maintenance_date', 'description', 'cost_amount', 'cost_currency', 'responsible_user_id', 'idempotency_key']);
        $data['responsible_user_id'] = (int) $data['responsible_user_id'];
        $maintenance = $action->execute($poultryHouse, $data, $actor);

        return (new MaintenanceResource($maintenance))->response()->setStatusCode($maintenance->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateMaintenanceRequest $request, Maintenance $maintenance, UpdateMaintenanceAction $action): MaintenanceResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->safe()->only(['maintenance_date', 'description', 'cost_amount', 'cost_currency', 'responsible_user_id']);

        if (isset($data['responsible_user_id'])) {
            $data['responsible_user_id'] = (int) $data['responsible_user_id'];
        }

        return new MaintenanceResource($action->execute(
            $maintenance, $data, (int) $request->validated('version'), $request->validated('reason'), $actor,
        ));
    }

    public function cancel(CancelMaintenanceRequest $request, Maintenance $maintenance, CancelMaintenanceAction $action): MaintenanceResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new MaintenanceResource($action->execute(
            $maintenance, (int) $request->validated('version'), $request->validated('reason'), $actor,
        ));
    }
}
