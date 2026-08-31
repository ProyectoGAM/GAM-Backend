<?php

namespace App\Modules\FarmStructure\Application\PublicApi\Data;

final readonly class LockedPoultryHouseData
{
    public function __construct(
        public int $id,
        public int $productionUnitId,
        public int $capacity,
        public int $occupancy,
        public bool $canReceive,
    ) {}

    public function supports(int $netIncrease): bool
    {
        return $this->occupancy + $netIncrease >= 0
            && $this->occupancy + $netIncrease <= $this->capacity;
    }
}
