<?php

namespace App\Modules\FarmStructure\Application\Queries;

use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListProductionUnitsQuery
{
    /** @param array{search?: string, locality_id?: int, status?: string, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return ProductionUnit::query()
            ->with('locality.department')
            ->withCount('poultryHouses')
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'),
            )
            ->when(
                $filters['locality_id'] ?? null,
                fn (Builder $query, int $localityId): Builder => $query->where('locality_id', $localityId),
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
