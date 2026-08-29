<?php

namespace App\Modules\FarmStructure\Infrastructure\Occupancy;

use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;

final readonly class EmptyPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return 0;
    }
}
