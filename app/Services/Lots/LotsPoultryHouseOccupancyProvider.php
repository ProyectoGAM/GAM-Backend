<?php

namespace App\Services\Lots;

use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\Lots\Flock;

final readonly class LotsPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return (int) Flock::query()->where('poultry_house_id', $poultryHouseId)->sum('current_quantity');
    }
}
