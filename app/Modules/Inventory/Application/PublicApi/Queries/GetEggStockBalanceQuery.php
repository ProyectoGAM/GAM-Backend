<?php

namespace App\Modules\Inventory\Application\PublicApi\Queries;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockBalance;
use App\Modules\Inventory\Application\PublicApi\Actions\EnsureEggStockAccountAction;

final readonly class GetEggStockBalanceQuery
{
    public function __construct(private EnsureEggStockAccountAction $accounts) {}

    /** @return array{unidad_productiva_id:int,saldo:int} */
    public function execute(ProductionUnit $unit): array
    {
        $account = $this->accounts->execute($unit, create: false);
        if ($account === null) {
            return ['unidad_productiva_id' => $unit->getKey(), 'saldo' => 0];
        }
        $balance = StockBalance::query()->where('product_id', $account->product_id)->where('stock_location_id', $account->stock_location_id)->value('on_hand_quantity');

        return ['unidad_productiva_id' => $unit->getKey(), 'saldo' => (int) round((float) ($balance ?? 0))];
    }
}
