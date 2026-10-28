<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InventoryConflict;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockLocation;
use Illuminate\Database\Eloquent\Collection;

final readonly class StockLocationLocks
{
    /**
     * Bloquea primero UP y galpones, después ubicaciones, siempre en orden de ID.
     * Se invoca dentro de una transacción después de bloquear los productos.
     *
     * @param  list<int>  $ids
     * @return Collection<int, StockLocation>
     */
    public function lock(array $ids): Collection
    {
        $metadata = StockLocation::query()->whereIn('id', $ids)->get()->keyBy('id');
        ProductionUnit::query()->whereIn('id', $metadata->pluck('production_unit_id')->filter())->orderBy('id')->sharedLock()->get();
        PoultryHouse::query()->whereIn('id', $metadata->pluck('poultry_house_id')->filter())->orderBy('id')->sharedLock()->get();
        $locations = StockLocation::query()->whereIn('id', $ids)->with(['productionUnit', 'poultryHouse'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($locations as $location) {
            $original = $metadata->get($location->getKey());
            if ($original === null || $original->production_unit_id !== $location->production_unit_id || $original->poultry_house_id !== $location->poultry_house_id) {
                throw new InventoryConflict('La ubicación cambió mientras se registraba el movimiento. Vuelve a intentarlo.');
            }
        }
        if ($locations->count() !== count(array_unique($ids))) {
            throw new InventoryConflict('Una de las ubicaciones indicadas no existe.');
        }

        return $locations;
    }
}
