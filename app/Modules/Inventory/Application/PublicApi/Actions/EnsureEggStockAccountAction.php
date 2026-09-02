<?php

namespace App\Modules\Inventory\Application\PublicApi\Actions;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class EnsureEggStockAccountAction
{
    public function execute(ProductionUnit $unit, bool $create = true): ?EggStockAccount
    {
        $existing = EggStockAccount::query()->where('production_unit_id', $unit->getKey())->first();
        if ($existing !== null || ! $create) {
            return $existing?->load(['productionUnit', 'product', 'stockLocation']);
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($unit): EggStockAccount {
                    $product = Product::query()->where('system_key', 'generic_egg')->lockForUpdate()->first();
                    if ($product === null) {
                        $product = new Product;
                        $product->forceFill([
                            'sku' => 'HUEVO',
                            'name' => 'Huevo',
                            'kind' => ProductKind::Egg,
                            'base_unit' => BaseUnit::Unit,
                            'stock_tracked' => true,
                            'status' => ProductStatus::Active,
                            'system_key' => 'generic_egg',
                        ])->save();
                    }

                    $account = EggStockAccount::query()->where('production_unit_id', $unit->getKey())->lockForUpdate()->first();
                    if ($account === null) {
                        $location = StockLocation::query()->where('system_managed', true)->where('name', 'Cuenta de huevos - UP '.$unit->getKey())->first();
                        if ($location === null) {
                            $location = new StockLocation;
                            $location->forceFill([
                                'production_unit_id' => $unit->getKey(),
                                'name' => 'Cuenta de huevos - UP '.$unit->getKey(),
                                'status' => 'active',
                                'system_managed' => true,
                            ])->save();
                        }
                        $account = new EggStockAccount;
                        $account->forceFill([
                            'production_unit_id' => $unit->getKey(),
                            'product_id' => $product->getKey(),
                            'stock_location_id' => $location->getKey(),
                        ])->save();
                    }

                    $balance = StockBalance::query()->where('product_id', $account->product_id)->where('stock_location_id', $account->stock_location_id)->lockForUpdate()->first();
                    if ($balance === null) {
                        $balance = new StockBalance;
                        $balance->forceFill(['product_id' => $account->product_id, 'stock_location_id' => $account->stock_location_id, 'on_hand_quantity' => '0.000000', 'minimum_quantity' => '0.000000', 'allow_negative' => true])->save();
                    } elseif (! $balance->allow_negative) {
                        $balance->forceFill(['allow_negative' => true])->save();
                    }

                    return $account->load(['productionUnit', 'product', 'stockLocation']);
                }, 3);
            } catch (QueryException $exception) {
                if ((string) ($exception->errorInfo[0] ?? '') !== '23505' || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('No fue posible materializar la cuenta técnica de huevos.');
    }
}
