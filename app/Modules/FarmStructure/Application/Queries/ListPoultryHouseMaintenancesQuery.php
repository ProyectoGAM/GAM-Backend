<?php

namespace App\Modules\FarmStructure\Application\Queries;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListPoultryHouseMaintenancesQuery
{
    /**
     * @param  array{status?: string, date_from?: string, date_to?: string, per_page?: int, page?: int}  $filters
     * @return LengthAwarePaginator<int, Maintenance>
     */
    public function execute(PoultryHouse $poultryHouse, array $filters): LengthAwarePaginator
    {
        return Maintenance::query()
            ->whereBelongsTo($poultryHouse)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('maintenance_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('maintenance_date', '<=', $date))
            ->orderByDesc('maintenance_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);
    }
}
