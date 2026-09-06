<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\SetMinimumStockAction;
use App\Http\Requests\Inventory\ListInventoryMovementsRequest;
use App\Http\Requests\Inventory\ListStockBalancesRequest;
use App\Http\Requests\Inventory\SetMinimumStockRequest;
use App\Http\Requests\Inventory\ViewInventoryMovementRequest;
use App\Http\Resources\Inventory\InventoryMovementResource;
use App\Http\Resources\Inventory\StockBalanceResource;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\User;
use App\Queries\Inventory\GetInventoryMovementQuery;
use App\Queries\Inventory\ListInventoryMovementsQuery;
use App\Queries\Inventory\ListStockBalancesQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class InventoryReadController
{
    public function balances(ListStockBalancesRequest $request, ListStockBalancesQuery $query): AnonymousResourceCollection
    {
        return StockBalanceResource::collection($query->execute($request->validated()));
    }

    public function movements(ListInventoryMovementsRequest $request, ListInventoryMovementsQuery $query): AnonymousResourceCollection
    {
        return InventoryMovementResource::collection($query->execute($request->validated()));
    }

    public function movement(ViewInventoryMovementRequest $request, InventoryMovement $inventoryMovement, GetInventoryMovementQuery $query): InventoryMovementResource
    {
        return new InventoryMovementResource($query->execute($inventoryMovement));
    }

    public function minimum(SetMinimumStockRequest $request, StockBalance $stockBalance, SetMinimumStockAction $action): StockBalanceResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockBalanceResource($action->execute($stockBalance, (string) $request->validated('minimum_quantity'), $actor));
    }
}
