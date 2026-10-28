<?php

namespace App\Services\Inventory;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Builder;

final readonly class StockProductCompatibility
{
    /**
     * Aplica la misma compatibilidad que se exige al registrar movimientos.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function constrain(Builder $query, StockLocation $location): Builder
    {
        $query->whereIn('kind', $this->allowedKinds($location));

        if ($this->isFeedLocation($location)) {
            $query->where('base_unit', BaseUnit::Gram->value);
        }

        return $query;
    }

    public function assertCompatible(Product $product, StockLocation $location): void
    {
        $isFeedLocation = $this->isFeedLocation($location);

        if ($location->poultry_house_id !== null && ! $isFeedLocation) {
            throw new InventoryConflict('La ubicación vinculada no corresponde a una planta de ración.');
        }

        if (! in_array($product->kind, $this->allowedKinds($location), true)) {
            throw new InventoryConflict($isFeedLocation
                ? 'Una planta de ración sólo puede almacenar materias primas.'
                : 'Las materias primas sólo pueden moverse en plantas de ración.');
        }

        if ($isFeedLocation && ($product->base_unit !== BaseUnit::Gram || ! $product->stock_tracked)) {
            throw new InventoryConflict('Las materias primas de una planta de ración deben controlar stock en gramos.');
        }
    }

    /** @return list<ProductKind> */
    private function allowedKinds(StockLocation $location): array
    {
        if ($this->isFeedLocation($location)) {
            return [ProductKind::RawMaterial];
        }

        if ($location->poultry_house_id !== null) {
            return [];
        }

        return array_values(array_filter(ProductKind::cases(), static fn (ProductKind $kind): bool => $kind !== ProductKind::RawMaterial));
    }

    private function isFeedLocation(StockLocation $location): bool
    {
        return $location->poultry_house_id !== null && $location->poultryHouse?->type === PoultryHouseType::Feed;
    }
}
