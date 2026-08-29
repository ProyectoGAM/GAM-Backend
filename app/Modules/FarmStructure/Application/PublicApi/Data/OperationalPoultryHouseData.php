<?php

namespace App\Modules\FarmStructure\Application\PublicApi\Data;

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
