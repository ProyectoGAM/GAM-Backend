<?php

namespace App\Modules\Inventory\Application\Queries;

use App\Models\Inventory\StockBalance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListStockBalancesQuery
{
    /** @param array{product_id?: int, stock_location_id?: int, below_minimum?: bool, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return StockBalance::query()
            ->with(['product', 'stockLocation'])
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $productId): Builder => $query->where('product_id', $productId))
            ->when($filters['stock_location_id'] ?? null, fn (Builder $query, int $locationId): Builder => $query->where('stock_location_id', $locationId))
            ->when($filters['below_minimum'] ?? false, fn (Builder $query): Builder => $query->whereRaw('(on_hand_quantity - reserved_quantity) < minimum_quantity'))
            ->orderBy('stock_location_id')
            ->orderBy('product_id')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
