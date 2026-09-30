<?php

namespace App\DTO\FarmStructure;

final readonly class OperationalPoultryHouseData
{
    public function __construct(
        public int $poultryHouseId,
        public int $productionUnitId,
        public int $birdCapacity,
        public int $occupancy,
        public int $availableCapacity,
    ) {}
}
