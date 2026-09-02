<?php

namespace App\Modules\Inventory\Application\PublicApi\Queries;

use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\InventoryMovement;

final readonly class GetEggStockTransactionQuery
{
    public function execute(EggStockTransaction $transaction): EggStockTransaction
    {
        $transaction->load(['revisions', 'productionUnit']);
        $transaction->setAttribute('inventory_references', InventoryMovement::query()->where('reference_type', 'egg_stock_transaction')->where('reference_id', $transaction->public_id)->orWhere(function ($query) use ($transaction): void {
            $query->where('reference_type', 'egg_stock_revision')->where('reference_id', $transaction->public_id);
        })->pluck('id')->all());

        return $transaction;
    }
}
