<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\ChangeStockLocationStatusAction;
use App\Modules\Inventory\Domain\Enums\StockLocationStatus;
use App\Modules\Inventory\Http\Requests\ChangeStockLocationStatusRequest;
use App\Modules\Inventory\Http\Resources\StockLocationResource;

final readonly class StockLocationStatusController
{
    public function update(ChangeStockLocationStatusRequest $request, StockLocation $ubicacionStock, ChangeStockLocationStatusAction $action): StockLocationResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockLocationResource($action->execute($ubicacionStock, StockLocationStatus::from($request->string('estado')->toString()), $actor));
    }
}
