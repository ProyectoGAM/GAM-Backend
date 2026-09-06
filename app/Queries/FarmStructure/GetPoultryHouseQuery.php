<?php

namespace App\Queries\FarmStructure;

use App\Models\FarmStructure\PoultryHouse;

final readonly class GetPoultryHouseQuery
{
    public function execute(int $poultryHouseId): PoultryHouse
    {
        return PoultryHouse::query()
            ->with('productionUnit.locality.department')
            ->findOrFail($poultryHouseId);
    }
}
