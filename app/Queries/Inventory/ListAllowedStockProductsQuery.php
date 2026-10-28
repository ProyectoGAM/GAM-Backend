<?php

namespace App\Queries\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Services\Inventory\StockProductCompatibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListAllowedStockProductsQuery
{
    public function __construct(private StockProductCompatibility $compatibility) {}

    /**
     * @param  array{stock_location_id: int, operation: string, search?: string, per_page?: int, page?: int}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $location = StockLocation::query()->with('poultryHouse')->findOrFail($filters['stock_location_id']);
        $query = Product::query()
            ->select('products.*')
            ->addSelect(['available_quantity' => StockBalance::query()
                ->select('on_hand_quantity')
                ->whereColumn('product_id', 'products.id')
                ->where('stock_location_id', $location->getKey()),
            ])
            ->where('status', ProductStatus::Active->value)
            ->where('stock_tracked', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('system_key')->orWhere('system_key', '!=', 'generic_egg'))
            ->whereNotIn('products.id', EggStockAccount::query()->select('product_id')->where('stock_location_id', $location->getKey()));

        $this->compatibility->constrain($query, $location);

        if (! $this->compatibility->isOperational($location)) {
            $query->whereKey([]);
        }

        if ($filters['operation'] === InventoryMovementType::Loss->value) {
            $query->whereHas('stockBalances', fn (Builder $balance): Builder => $balance
                ->where('stock_location_id', $location->getKey())
                ->where('on_hand_quantity', '>', 0));
        }

        return $query
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->appends($filters);
    }
}
