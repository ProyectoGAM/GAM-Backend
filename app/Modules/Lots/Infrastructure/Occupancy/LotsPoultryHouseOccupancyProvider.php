<?php

namespace App\Modules\Lots\Infrastructure\Occupancy;

use App\Models\Lots\Flock;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;

final readonly class LotsPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return (int) Flock::query()->where('poultry_house_id', $poultryHouseId)->sum('current_quantity');
    }
}
