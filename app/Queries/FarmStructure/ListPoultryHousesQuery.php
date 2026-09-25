<?php

namespace App\Queries\FarmStructure;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListPoultryHousesQuery
{
    public function __construct(private PoultryHouseOccupancyProvider $occupancyProvider) {}

    /** @param array{search?: string, status?: string, type?: string, per_page?: int} $filters */
    public function execute(ProductionUnit $productionUnit, array $filters): LengthAwarePaginator
    {
        $poultryHouses = PoultryHouse::query()
            ->whereBelongsTo($productionUnit)
            ->with('productionUnit.locality.department')
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $filters['type'] ?? null,
                fn (Builder $query, string $type): Builder => $query->where('type', $type),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        $poultryHouseIds = $poultryHouses->getCollection()
            ->filter(fn (PoultryHouse $poultryHouse): bool => $poultryHouse->type === PoultryHouseType::Poultry)
            ->modelKeys();
        $occupancies = $poultryHouseIds === []
            ? []
            : $this->occupancyProvider->occupanciesFor(array_map(
                static fn (int|string $id): int => (int) $id,
                $poultryHouseIds,
            ));

        $poultryHouses->getCollection()->each(function (PoultryHouse $poultryHouse) use ($occupancies): void {
            $poultryHouse->setAttribute(
                'current_occupancy',
                $poultryHouse->type === PoultryHouseType::Poultry
                    ? ($occupancies[(int) $poultryHouse->getKey()] ?? 0)
                    : null,
            );
        });

        return $poultryHouses;
    }
}
