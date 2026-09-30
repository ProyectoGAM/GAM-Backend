<?php

namespace App\Interfaces\FarmStructure;

interface PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int;

    /** @param list<int> $poultryHouseIds
     * @return array<int, int>
     */
    public function occupanciesFor(array $poultryHouseIds): array;

    public function openFlocksCountFor(int $poultryHouseId): int;
}
