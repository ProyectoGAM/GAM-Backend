<?php

namespace App\Interfaces\FarmStructure;

interface PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int;
}
