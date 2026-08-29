<?php

namespace App\Modules\FarmStructure\Application\Queries;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListPoultryHousesQuery
{
    /** @param array{search?: string, status?: string, per_page?: int} $filters */
    public function execute(ProductionUnit $productionUnit, array $filters): LengthAwarePaginator
    {
        return PoultryHouse::query()
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
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
