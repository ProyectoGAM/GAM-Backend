<?php

namespace App\Modules\FarmStructure\Application\Queries;

use App\Models\FarmStructure\ProductionUnit;

final readonly class GetProductionUnitQuery
{
    public function execute(int $productionUnitId): ProductionUnit
    {
        return ProductionUnit::query()
            ->with('locality.department')
            ->withCount('poultryHouses')
            ->findOrFail($productionUnitId);
    }
}
