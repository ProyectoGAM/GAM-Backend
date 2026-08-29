<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Queries;

use App\Models\SuppliersAndCatalogs\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListSuppliersQuery
{
    /** @param array{search?: string, status?: string, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Supplier::query()
            ->with('locality.department')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
