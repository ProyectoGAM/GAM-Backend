<?php

namespace App\Queries\FarmStructure;

use App\DTO\FarmStructure\OperationalPoultryHouseData;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\ValueObjects\FarmStructure\BirdCapacity;
use Illuminate\Database\Eloquent\Builder;

final readonly class GetOperationalPoultryHouseQuery
{
    public function __construct(private PoultryHouseOccupancyProvider $occupancyProvider) {}

    public function execute(int $poultryHouseId): OperationalPoultryHouseData
    {
        $poultryHouse = PoultryHouse::query()
            ->where('status', PoultryHouseStatus::Operational)
            ->whereHas(
                'productionUnit',
                fn (Builder $query): Builder => $query->where('status', ProductionUnitStatus::Active),
            )
            ->findOrFail($poultryHouseId);

        $capacity = BirdCapacity::fromInt($poultryHouse->bird_capacity);
        $occupancy = $this->occupancyProvider->occupancyFor($poultryHouseId);

        return new OperationalPoultryHouseData(
            poultryHouseId: (int) $poultryHouse->getKey(),
            productionUnitId: $poultryHouse->production_unit_id,
            birdCapacity: $capacity->value(),
            occupancy: $occupancy,
            availableCapacity: $capacity->availableFor($occupancy),
        );
    }
}
