<?php

namespace App\Queries\Inventory;

use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Services\Inventory\StockProductCompatibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class ListStockBalancesQuery
{
    public function __construct(private StockProductCompatibility $compatibility) {}

    /** @param array{product_id?: int, stock_location_id?: int, below_minimum?: bool, incompatible_only?: bool, per_page?: int, page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return StockBalance::query()
            ->with([
                'product' => function (Relation $productRelation): void {
                    /** @var Builder<Product> $productQuery */
                    $productQuery = $productRelation->getQuery();
                    $productQuery->withResourceMetadata();
                },
                'stockLocation.poultryHouse:id,name',
            ])
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $productId): Builder => $query->where('product_id', $productId))
            ->when($filters['stock_location_id'] ?? null, fn (Builder $query, int $locationId): Builder => $query->where('stock_location_id', $locationId))
            ->when($filters['below_minimum'] ?? false, fn (Builder $query): Builder => $query->whereColumn('on_hand_quantity', '<', 'minimum_quantity'))
            ->when($filters['incompatible_only'] ?? false, fn (Builder $query): Builder => $query->where('on_hand_quantity', '!=', 0)->whereNotIn('stock_balances.id', $this->compatibility->compatibleBalances()->select('stock_balances.id')))
            ->orderBy('stock_location_id')
            ->orderBy('product_id')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
