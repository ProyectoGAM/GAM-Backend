<?php

namespace App\DTO\FarmStructure;

final readonly class LockedPoultryHouseData
{
    public function __construct(
        public int $id,
        public int $productionUnitId,
        public int $capacity,
        public int $occupancy,
        public int $openFlocksCount,
        public bool $canReceive,
    ) {}

}
