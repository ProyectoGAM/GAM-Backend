<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\SetMinimumStockAction;
use App\Modules\Inventory\Application\Queries\GetInventoryMovementQuery;
use App\Modules\Inventory\Application\Queries\ListInventoryMovementsQuery;
use App\Modules\Inventory\Application\Queries\ListStockBalancesQuery;
use App\Modules\Inventory\Application\Queries\ListStockReservationsQuery;
use App\Modules\Inventory\Http\Requests\ListInventoryMovementsRequest;
use App\Modules\Inventory\Http\Requests\ListStockBalancesRequest;
use App\Modules\Inventory\Http\Requests\ListStockReservationsRequest;
use App\Modules\Inventory\Http\Requests\SetMinimumStockRequest;
use App\Modules\Inventory\Http\Requests\ViewInventoryMovementRequest;
use App\Modules\Inventory\Http\Resources\InventoryMovementResource;
use App\Modules\Inventory\Http\Resources\StockBalanceResource;
use App\Modules\Inventory\Http\Resources\StockReservationResource;
use App\Support\PublicInputMapper;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class InventoryReadController
{
    public function balances(ListStockBalancesRequest $request, ListStockBalancesQuery $query): AnonymousResourceCollection
    {
        return StockBalanceResource::collection($query->execute(PublicInputMapper::toInternal($request->validated(), 'inventory')));
    }

    public function movimientos(ListInventoryMovementsRequest $request, ListInventoryMovementsQuery $query): AnonymousResourceCollection
    {
        return InventoryMovementResource::collection($query->execute(PublicInputMapper::toInternal($request->validated(), 'inventory')));
    }

    public function movement(ViewInventoryMovementRequest $request, InventoryMovement $inventoryMovement, GetInventoryMovementQuery $query): InventoryMovementResource
    {
        return new InventoryMovementResource($query->execute($inventoryMovement));
    }

    public function reservations(ListStockReservationsRequest $request, ListStockReservationsQuery $query): AnonymousResourceCollection
    {
        return StockReservationResource::collection($query->execute(PublicInputMapper::toInternal($request->validated(), 'inventory')));
    }

    public function minimum(SetMinimumStockRequest $request, StockBalance $stockBalance, SetMinimumStockAction $action): StockBalanceResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockBalanceResource($action->execute($stockBalance, (string) PublicInputMapper::toInternal(['cantidad_minima' => $request->validated('cantidad_minima')], 'inventory')['minimum_quantity'], $actor));
    }
}
