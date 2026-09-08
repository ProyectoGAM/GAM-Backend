<?php

namespace App\Queries\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListVaccinesQuery
{
    /**
     * @param  array{buscar?: string, sku?: string, proveedor_id?: int, estado?: string, per_page?: int, page?: int}  $filters
     * @return LengthAwarePaginator<int, Vaccine>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Vaccine::query()
            ->with(['product', 'supplier'])
            ->when($filters['buscar'] ?? null, function (Builder $query, string $search): Builder {
                $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                return $query->whereHas('product', fn (Builder $products): Builder => $products->where(function (Builder $match) use ($literal): void {
                    $match->where('name', 'ilike', $literal)->orWhere('sku', 'ilike', $literal);
                }));
            })
            ->when($filters['sku'] ?? null, fn (Builder $query, string $sku): Builder => $query->whereHas('product', fn (Builder $products): Builder => $products->where('sku', $sku)))
            ->when($filters['proveedor_id'] ?? null, fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId))
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status): Builder => $query->whereHas('product', fn (Builder $products): Builder => $products->where('status', $status)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1)
            ->withQueryString();
    }
}
