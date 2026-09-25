<?php

namespace App\Queries\Inventory;

use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockBalance;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

final readonly class GetFeedStockQuery
{
    /** @return array{scope:string, scope_id:int|null, items:list<array<string,mixed>>} */
    public function execute(?PoultryHouse $house = null, ?ProductionUnit $productionUnit = null): array
    {
        if ($house !== null && $house->type !== PoultryHouseType::Feed) {
            throw new InventoryConflict('El galpón indicado no es una planta de ración.');
        }

        $balances = StockBalance::query()
            ->with(['product', 'stockLocation.poultryHouse', 'stockLocation.productionUnit'])
            ->whereHas('stockLocation', fn (Builder $query): Builder => $query->whereNotNull('poultry_house_id'))
            ->when($house !== null, fn (Builder $query): Builder => $query->whereHas('stockLocation', fn (Builder $locations): Builder => $locations->where('poultry_house_id', $house->getKey())))
            ->when($productionUnit !== null, fn (Builder $query): Builder => $query->whereHas('stockLocation', fn (Builder $locations): Builder => $locations->where('production_unit_id', $productionUnit->getKey())))
            ->orderBy('product_id')
            ->orderBy('stock_location_id')
            ->get();

        $items = [];
        foreach ($balances as $balance) {
            $location = $balance->stockLocation;
            $plant = $location->poultryHouse;
            if ($plant === null || $balance->product === null) {
                continue;
            }

            $included = $plant->status !== PoultryHouseStatus::Inactive;
            $productId = (int) $balance->product_id;
            if (! isset($items[$productId])) {
                $items[$productId] = [
                    'product_id' => $productId,
                    'product' => $balance->product,
                    'total_g_decimal' => BigDecimal::zero()->toScale(6),
                    'details' => [],
                ];
            }

            if ($included) {
                $items[$productId]['total_g_decimal'] = $items[$productId]['total_g_decimal']
                    ->plus((string) $balance->on_hand_quantity)
                    ->toScale(6);
            }

            $items[$productId]['details'][] = [
                'plant_id' => (int) $plant->getKey(),
                'plant_name' => $plant->name,
                'poultry_house_id' => (int) $plant->getKey(),
                'production_unit_id' => (int) $location->production_unit_id,
                'stock_location_id' => (int) $location->getKey(),
                'stock_g' => (string) $balance->on_hand_quantity,
                'is_negative' => BigDecimal::of((string) $balance->on_hand_quantity)->isNegative(),
                'included_in_totals' => $included,
            ];
        }

        $scope = $house !== null ? 'plant' : ($productionUnit !== null ? 'production_unit' : 'global');
        $scopeId = $house?->getKey() ?? $productionUnit?->getKey();

        return [
            'scope' => $scope,
            'scope_id' => $scopeId === null ? null : (int) $scopeId,
            'items' => array_values(array_map(static function (array $item): array {
                $total = $item['total_g_decimal'];

                return [
                    'product_id' => $item['product_id'],
                    'product' => $item['product'],
                    'total_g' => (string) $total,
                    'is_negative' => $total->isNegative(),
                    'details' => $item['details'],
                ];
            }, $items)),
        ];
    }
}
