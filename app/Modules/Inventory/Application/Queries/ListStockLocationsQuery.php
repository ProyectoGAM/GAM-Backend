<?php

namespace App\Modules\Inventory\Application\Queries;

use App\Models\Inventory\StockLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListStockLocationsQuery
{
    /** @param array{search?: string, status?: string, production_unit_id?: int, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return StockLocation::query()
            ->with('productionUnit')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['production_unit_id'] ?? null, fn (Builder $query, int $productionUnitId): Builder => $query->where('production_unit_id', $productionUnitId))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
