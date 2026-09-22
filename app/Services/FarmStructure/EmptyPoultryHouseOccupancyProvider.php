<?php

namespace App\Services\FarmStructure;

use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;

final readonly class EmptyPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return 0;
    }

    public function openFlocksCountFor(int $poultryHouseId): int
    {
        return 0;
    }
}
