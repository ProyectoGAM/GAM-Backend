<?php

namespace App\Modules\Inventory\Application\Queries;

use App\Models\Inventory\StockReservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListStockReservationsQuery
{
    /** @param array{status?: string, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return StockReservation::query()
            ->with(['lines.product', 'lines.stockLocation', 'creator'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
