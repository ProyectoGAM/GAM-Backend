<?php

namespace App\Services\Lots;

use App\Enums\Lots\FlockStatus;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\Lots\Flock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class LotsPoultryHouseOccupancyProvider implements PoultryHouseOccupancyProvider
{
    public function occupancyFor(int $poultryHouseId): int
    {
        return (int) ($this->occupancyByHouse($this->openFlocks()->where('poultry_house_id', $poultryHouseId))
            ->toBase()->first()?->occupancy ?? 0);
    }

    /** @param list<int> $poultryHouseIds
     * @return array<int, int>
     */
    public function occupanciesFor(array $poultryHouseIds): array
    {
        if ($poultryHouseIds === []) {
            return [];
        }

        $occupancies = $this->occupancyByHouse($this->openFlocks()->whereIn('poultry_house_id', $poultryHouseIds))->toBase()
            ->pluck('occupancy', 'poultry_house_id')
            ->map(fn (mixed $occupancy): int => (int) $occupancy)
            ->all();

        return array_replace(array_fill_keys($poultryHouseIds, 0), $occupancies);
    }

    public function openFlocksCountFor(int $poultryHouseId): int
    {
        return $this->openFlocks()->where('poultry_house_id', $poultryHouseId)->count();
    }

    public function totalOccupancy(?int $unitId): int
    {
        $flocks = $this->openFlocks()->when($unitId !== null, fn (Builder $query): Builder => $query->where('production_unit_id', $unitId));

        return (int) DB::query()->fromSub($this->occupancyByHouse($flocks)->toBase(), 'houses')->sum('occupancy');
    }

    /** @return Builder<Flock> */
    private function openFlocks(): Builder
    {
        return Flock::query()->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined]);
    }

    /** @param Builder<Flock> $flocks @return Builder<Flock> */
    private function occupancyByHouse(Builder $flocks): Builder
    {
        return $flocks->selectRaw('poultry_house_id, GREATEST(0, SUM(current_quantity)) as occupancy')->groupBy('poultry_house_id');
    }
}
