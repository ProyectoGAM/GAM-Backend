<?php

namespace App\Services\Lots;

use App\Enums\Lots\FlockStatus;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\Lots\Flock;

final readonly class LotsPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return (int) Flock::query()
            ->where('poultry_house_id', $poultryHouseId)
            ->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->sum('current_quantity');
    }

    /** @param list<int> $poultryHouseIds
     * @return array<int, int>
     */
    public function occupanciesFor(array $poultryHouseIds): array
    {
        if ($poultryHouseIds === []) {
            return [];
        }

        $occupancies = Flock::query()
            ->whereIn('poultry_house_id', $poultryHouseIds)
            ->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->selectRaw('poultry_house_id, SUM(current_quantity) as occupancy')
            ->groupBy('poultry_house_id')
            ->pluck('occupancy', 'poultry_house_id')
            ->map(fn (mixed $occupancy): int => (int) $occupancy)
            ->all();

        return array_replace(array_fill_keys($poultryHouseIds, 0), $occupancies);
    }

    public function openFlocksCountFor(int $poultryHouseId): int
    {
        return Flock::query()
            ->where('poultry_house_id', $poultryHouseId)
            ->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])
            ->count();
    }
}
