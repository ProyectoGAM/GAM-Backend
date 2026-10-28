<?php

namespace App\Services\Inventory;

use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Inventory\StockLocationStatus;
use App\Enums\Inventory\StockLocationType;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Builder;

final readonly class StockProductCompatibility
{
    /** @param Builder<Product> $query @return Builder<Product> */
    public function constrain(Builder $query, StockLocation $location): Builder
    {
        $query->whereIn('kind', $this->allowedKinds($location));
        if ($location->type === StockLocationType::FeedPlant) {
            $query->where('base_unit', BaseUnit::Gram->value);
        }

        return $query;
    }

    /** @return list<ProductKind> */
    public function allowedKinds(StockLocation $location): array
    {
        return $this->hasValidStructure($location) ? $location->type->allowedKinds() : [];
    }

    public function isOperational(StockLocation $location): bool
    {
        return $location->status === StockLocationStatus::Active
            && ($location->production_unit_id === null || $location->productionUnit?->status === ProductionUnitStatus::Active)
            && ($location->type !== StockLocationType::FeedPlant || $location->poultryHouse?->status === PoultryHouseStatus::Operational);
    }

    public function isCompatible(Product $product, StockLocation $location): bool
    {
        return in_array($product->kind, $this->allowedKinds($location), true)
            && $product->stock_tracked
            && ($location->type !== StockLocationType::FeedPlant || $product->base_unit === BaseUnit::Gram);
    }

    public function assertCompatible(Product $product, StockLocation $location): void
    {
        if ($this->isCompatible($product, $location)) {
            return;
        }
        if ($product->kind === ProductKind::RawMaterial && $location->type !== StockLocationType::FeedPlant) {
            throw new InventoryConflict('Las materias primas sólo pueden moverse en plantas de ración.');
        }
        throw new InventoryConflict(match ($location->type) {
            StockLocationType::FeedPlant => 'Una planta de ración sólo puede almacenar materias primas.',
            StockLocationType::Warehouse => 'Una cámara de insumos sólo puede almacenar insumos y otros productos.',
            StockLocationType::Health => 'Una ubicación de sanidad sólo puede almacenar medicamentos y vacunas.',
            StockLocationType::EggAccount => 'Una cuenta de huevos sólo puede almacenar huevos.',
            StockLocationType::Unknown => 'La ubicación no tiene un tipo de almacenamiento reconocido.',
        });
    }

    /** Identifica saldos históricos sin reclasificarlos ni modificarlos. @return Builder<StockBalance> */
    public function compatibleBalances(): Builder
    {
        return StockBalance::query()->where(function (Builder $query): void {
            foreach (StockLocationType::cases() as $type) {
                if ($type === StockLocationType::Unknown) {
                    continue;
                }
                $query->orWhere(function (Builder $branch) use ($type): void {
                    $branch->whereHas('product', function (Builder $product) use ($type): void {
                        $product->whereIn('kind', $type->allowedKinds())->where('stock_tracked', true);
                        if ($type === StockLocationType::FeedPlant) {
                            $product->where('base_unit', BaseUnit::Gram);
                        }
                    })->whereHas('stockLocation', function (Builder $location) use ($type): void {
                        $location->where('type', $type);
                        if ($type === StockLocationType::FeedPlant) {
                            $location->whereHas('poultryHouse', fn (Builder $house): Builder => $house->where('type', PoultryHouseType::Feed)->whereColumn('production_unit_id', 'stock_locations.production_unit_id'));
                        } else {
                            $location->whereNull('poultry_house_id');
                            if ($type === StockLocationType::Health) {
                                $location->whereNotNull('production_unit_id');
                            }
                            if ($type === StockLocationType::EggAccount) {
                                $location->whereIn('id', EggStockAccount::query()->select('stock_location_id')->whereColumn('production_unit_id', 'stock_locations.production_unit_id'));
                            }
                        }
                    });
                });
            }
        });
    }

    private function hasValidStructure(StockLocation $location): bool
    {
        if ($location->type === StockLocationType::FeedPlant) {
            return $location->poultry_house_id !== null && $location->poultryHouse?->type === PoultryHouseType::Feed
                && (int) $location->production_unit_id === (int) $location->poultryHouse->production_unit_id;
        }
        if ($location->poultry_house_id !== null) {
            return false;
        }

        return match ($location->type) {
            StockLocationType::EggAccount => EggStockAccount::query()->where('stock_location_id', $location->getKey())->where('production_unit_id', $location->production_unit_id)->exists(),
            StockLocationType::Health => $location->production_unit_id !== null,
            StockLocationType::Warehouse => true,
            default => false,
        };
    }
}
