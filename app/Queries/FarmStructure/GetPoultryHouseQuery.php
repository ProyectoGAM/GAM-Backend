<?php

namespace App\Queries\FarmStructure;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;

final readonly class GetPoultryHouseQuery
{
    public function __construct(private PoultryHouseOccupancyProvider $occupancyProvider) {}

    public function execute(int $poultryHouseId): PoultryHouse
    {
        $poultryHouse = PoultryHouse::query()
            ->with('productionUnit.locality.department')
            ->findOrFail($poultryHouseId);

        $poultryHouse->setAttribute('current_occupancy', null);
        if ($poultryHouse->type === PoultryHouseType::Poultry) {
            $occupancies = $this->occupancyProvider->occupanciesFor([$poultryHouseId]);
            $poultryHouse->setAttribute('current_occupancy', $occupancies[$poultryHouseId] ?? 0);
        }

        return $poultryHouse;
    }
}
