<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\RegularizeStockBalanceAction;
use App\Http\Requests\Inventory\RegularizeStockBalanceRequest;
use App\Http\Resources\Inventory\InventoryMovementResource;
use App\Models\Inventory\StockBalance;
use Illuminate\Http\JsonResponse;

final readonly class StockRegularizationController
{
    public function store(RegularizeStockBalanceRequest $request, StockBalance $stockBalance, RegularizeStockBalanceAction $action): JsonResponse
    {
        return (new InventoryMovementResource($action->execute($stockBalance, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }
}
