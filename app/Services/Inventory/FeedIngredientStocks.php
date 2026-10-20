<?php

namespace App\Services\Inventory;

use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\StockLocationStatus;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;

final readonly class FeedIngredientStocks
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public function products(array $ids, bool $requireActive = true): Collection
    {
        $products = Product::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
        if ($products->count() !== count($ids)) {
            throw new FeedRecipeConflict('Todos los ingredientes deben existir.');
        }
        foreach ($products as $product) {
            if ($product->kind !== ProductKind::RawMaterial || $product->base_unit !== BaseUnit::Gram || ! $product->stock_tracked
                || ($requireActive && $product->status !== ProductStatus::Active)) {
                throw new FeedRecipeConflict('Los ingredientes deben ser materias primas activas con stock en gramos.');
            }
        }

        return $products;
    }

    /**
     * Primero productos, luego plantas, ubicaciones y saldos en orden fijo.
     * El orden de consumo se decide únicamente después de adquirir los bloqueos.
     *
     * @param  list<int>  $requiredHouseIds
     * @return Collection<int, StockLocation>
     */
    public function locations(int $unitId, array $requiredHouseIds, bool $allPlants = false): Collection
    {
        $houses = PoultryHouse::query()->where('production_unit_id', $unitId)->where('type', PoultryHouseType::Feed)
            ->when(! $allPlants, fn ($query) => $query->whereIn('id', $requiredHouseIds))
            ->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($requiredHouseIds as $id) {
            if ($houses->get($id)?->status !== PoultryHouseStatus::Operational) {
                throw new FeedRecipeConflict('Todas las plantas involucradas deben estar operativas en la misma UP.');
            }
        }
        $operational = $houses->filter(fn (PoultryHouse $house): bool => $house->status === PoultryHouseStatus::Operational);
        $locations = StockLocation::query()->where('production_unit_id', $unitId)->whereIn('poultry_house_id', $operational->modelKeys())
            ->where('system_managed', true)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (StockLocation $location): bool => $location->status === StockLocationStatus::Active)->keyBy('id');
        foreach ($requiredHouseIds as $id) {
            if (! $locations->contains('poultry_house_id', $id)) {
                throw new FeedRecipeConflict('La planta no tiene una ubicación técnica activa.');
            }
        }

        return $locations;
    }

    /**
     * @param  list<int>  $productIds
     * @param  Collection<int, StockLocation>  $locations
     * @return Collection<int, StockBalance>
     */
    public function balances(array $productIds, Collection $locations): Collection
    {
        $rows = [];
        sort($productIds);
        foreach ($locations as $location) {
            foreach ($productIds as $productId) {
                $rows[] = [
                    'product_id' => $productId, 'stock_location_id' => $location->id,
                    'on_hand_quantity' => '0.000000', 'minimum_quantity' => '0.000000', 'allow_negative' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        StockBalance::query()->insertOrIgnore($rows);

        return StockBalance::query()->whereIn('product_id', $productIds)->whereIn('stock_location_id', $locations->modelKeys())
            ->orderBy('stock_location_id')->orderBy('product_id')->lockForUpdate()->get();
    }

    /**
     * Usa la escala existente para consumir saldos fraccionarios sin redondearlos.
     *
     * @param  array<int, int>  $quantities
     * @param  Collection<int, StockLocation>  $locations
     * @param  Collection<int, StockBalance>  $balances
     * @return list<array{product_id:int, stock_location_id:int, poultry_house_id:int, plant_name:string, quantity_grams:string, balance_before:string, balance_after:string}>
     */
    public function distribute(array $quantities, int $selectedHouseId, Collection $locations, Collection $balances): array
    {
        $distribution = [];
        $houses = PoultryHouse::query()->whereIn('id', $locations->pluck('poultry_house_id'))->get()->keyBy('id');
        foreach ($quantities as $productId => $required) {
            $eligible = $balances->filter(fn (StockBalance $balance): bool => (int) $balance->product_id === $productId
                && BigDecimal::of((string) $balance->on_hand_quantity)->compareTo(0) > 0)->all();
            usort($eligible, function (StockBalance $left, StockBalance $right) use ($selectedHouseId, $locations): int {
                $leftHouse = (int) $locations->get($left->stock_location_id)->poultry_house_id;
                $rightHouse = (int) $locations->get($right->stock_location_id)->poultry_house_id;
                $priority = (int) ($rightHouse === $selectedHouseId) <=> (int) ($leftHouse === $selectedHouseId);

                return $priority ?: (BigDecimal::of((string) $left->on_hand_quantity)->compareTo((string) $right->on_hand_quantity) ?: ($leftHouse <=> $rightHouse));
            });
            $remaining = BigDecimal::of($required);
            foreach ($eligible as $balance) {
                if ($remaining->isZero()) {
                    break;
                }
                $available = BigDecimal::of((string) $balance->on_hand_quantity);
                $taken = $available->compareTo($remaining) < 0 ? $available : $remaining;
                $houseId = (int) $locations->get($balance->stock_location_id)->poultry_house_id;
                $distribution[] = [
                    'product_id' => $productId, 'stock_location_id' => (int) $balance->stock_location_id,
                    'poultry_house_id' => $houseId, 'plant_name' => $houses->get($houseId)->name,
                    'quantity_grams' => (string) $taken->toScale(6),
                    'balance_before' => (string) $available->toScale(6),
                    'balance_after' => (string) $available->minus($taken)->toScale(6),
                ];
                $remaining = $remaining->minus($taken);
            }
            if (! $remaining->isZero()) {
                throw new FeedRecipeConflict('El stock elegible del ingrediente #'.$productId.' no alcanza para elaborar.');
            }
        }

        return $distribution;
    }
}
