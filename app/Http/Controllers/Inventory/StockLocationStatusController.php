<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ChangeStockLocationStatusAction;
use App\Enums\Inventory\StockLocationStatus;
use App\Http\Requests\Inventory\ChangeStockLocationStatusRequest;
use App\Http\Resources\Inventory\StockLocationResource;
use App\Models\Inventory\StockLocation;
use App\Models\User;

final readonly class StockLocationStatusController
{
    public function update(ChangeStockLocationStatusRequest $request, StockLocation $stockLocation, ChangeStockLocationStatusAction $action): StockLocationResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockLocationResource($action->execute($stockLocation, StockLocationStatus::from($request->string('status')->toString()), $actor));
    }
}
