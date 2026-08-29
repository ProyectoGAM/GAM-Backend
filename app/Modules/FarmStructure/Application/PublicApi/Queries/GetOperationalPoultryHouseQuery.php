<?php

namespace App\Modules\FarmStructure\Application\PublicApi\Queries;

use App\Models\FarmStructure\PoultryHouse;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;
use App\Modules\FarmStructure\Application\PublicApi\Data\OperationalPoultryHouseData;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\FarmStructure\Domain\ValueObjects\BirdCapacity;
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
