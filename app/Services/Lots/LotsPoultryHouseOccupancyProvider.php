<?php

namespace App\Services\Lots;

use App\Enums\Lots\FlockStatus;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\Lots\Flock;

final readonly class LotsPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return (int) Flock::query()
            ->where('poultry_house_id', $poultryHouseId)
            ->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->sum('current_quantity');
    }

    public function openFlocksCountFor(int $poultryHouseId): int
    {
        return Flock::query()
            ->where('poultry_house_id', $poultryHouseId)
            ->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->count();
    }
}
