<?php

namespace App\Queries\Inventory;

use App\Actions\Inventory\EnsureEggStockAccountAction;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockBalance;

final readonly class GetEggStockBalanceQuery
{
    public function __construct(private EnsureEggStockAccountAction $accounts) {}

    /** @return array{production_unit_id:int,balance:int} */
    public function execute(ProductionUnit $unit): array
    {
        $account = $this->accounts->execute($unit, create: false);
        if ($account === null) {
            return ['production_unit_id' => $unit->getKey(), 'balance' => 0];
        }
        $balance = StockBalance::query()->where('product_id', $account->product_id)->where('stock_location_id', $account->stock_location_id)->value('on_hand_quantity');

        return ['production_unit_id' => $unit->getKey(), 'balance' => (int) round((float) ($balance ?? 0))];
    }
}
