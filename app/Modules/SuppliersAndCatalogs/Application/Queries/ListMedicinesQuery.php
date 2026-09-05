<?php

namespace App\Modules\SuppliersAndCatalogs\Application\Queries;

use App\Models\SuppliersAndCatalogs\Medicine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListMedicinesQuery
{
    /**
     * @param  array{search?: string, supplier_id?: int, per_page?: int, page?: int}  $filters
     * @return LengthAwarePaginator<int, Medicine>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Medicine::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%'))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'pagina', $filters['page'] ?? 1)
            ->withQueryString();
    }
}
