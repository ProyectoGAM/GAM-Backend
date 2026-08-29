<?php

namespace App\Modules\FarmStructure\Application\PublicApi\Contracts;

interface PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int;
}
