<?php

namespace App\Queries\Deliveries;

use App\Enums\Deliveries\DeliveryStatus;
use App\Models\Deliveries\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListDeliveriesQuery
{
    /** @param array{status?:string,driver_id?:int,production_unit_id?:int,date_from?:string,date_to?:string,per_page?:int} $filters */
    public function execute(array $filters, ?DeliveryStatus $forcedStatus = null): LengthAwarePaginator
    {
        return Delivery::query()
            ->with([
                'driver:id,name',
                'productionUnit:id,name,latitude,longitude',
                'latestLocation',
                'returnProductionUnit:id,name',
            ])
            ->withCount([
                'stops',
                'stops as pending_stops_count' => fn (Builder $query): Builder => $query->where('status', 'pending'),
                'stops as delivered_stops_count' => fn (Builder $query): Builder => $query->where('status', 'delivered'),
                'stops as not_delivered_stops_count' => fn (Builder $query): Builder => $query->where('status', 'not_delivered'),
            ])
            ->withSum(['stops as delivered_amount' => fn (Builder $query): Builder => $query->where('status', 'delivered')], 'total_amount')
            ->when($forcedStatus, fn (Builder $query, DeliveryStatus $status): Builder => $query->where('status', $status))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['driver_id'] ?? null, fn (Builder $query, int $driverId): Builder => $query->where('driver_id', $driverId))
            ->when($filters['production_unit_id'] ?? null, fn (Builder $query, int $productionUnitId): Builder => $query->where(fn (Builder $origins): Builder => $origins->where('production_unit_id', $productionUnitId)->orWhereHas('loads', fn (Builder $loads): Builder => $loads->where('production_unit_id', $productionUnitId))))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '<=', $date))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);
    }
}
