<?php

namespace App\Modules\Inventory\Application\Queries;

use App\Models\Inventory\InventoryMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListInventoryMovementsQuery
{
    /** @param array<string, mixed> $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return InventoryMovement::query()
            ->with(['supplier', 'creator'])
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId))
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $productId): Builder => $query->whereHas('lines', fn (Builder $lines): Builder => $lines->where('product_id', $productId)))
            ->when($filters['stock_location_id'] ?? null, fn (Builder $query, int $locationId): Builder => $query->whereHas('lines', fn (Builder $lines): Builder => $lines->where('stock_location_id', $locationId)))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
