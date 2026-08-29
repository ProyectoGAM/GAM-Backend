<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Queries;

use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListProductsQuery
{
    /** @param array{search?: string, kind?: string, status?: string, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Product::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind): Builder => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
