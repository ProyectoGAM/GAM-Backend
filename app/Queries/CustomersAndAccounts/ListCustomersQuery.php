<?php

namespace App\Queries\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\Deliveries\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListCustomersQuery
{
    /** @return Builder<Customer> */
    public function query(array $filters): Builder
    {
        $query = Customer::query()->with(['account', 'locality.department']);
        if (! empty($filters['search'])) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $query->whereRaw("concat_ws(' ', name, business_name, document_number, phone, email, address, public_id) ILIKE ?", ['%'.$search.'%']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['account_status'])) {
            $operator = match ($filters['account_status']) {
                'debt' => '>', 'credit' => '<', default => '='
            };
            $query->whereHas('account', fn (Builder $account) => $account->where('balance', $operator, 0));
        }

        return $query->orderByRaw('COALESCE(name, business_name, public_id) ASC')->orderBy('id');
    }

    public function execute(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($filters['per_page'] ?? 50)->withQueryString();
    }

    public function operational(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)->with('lastDelivery.delivery:id,public_id')
            ->paginate($filters['per_page'] ?? $filters['limit'] ?? 50)->withQueryString();
    }

    public function markers(array $filters): array
    {
        $query = $this->query($filters)->withoutEagerLoads()->select(['id', 'public_id', 'name', 'business_name', 'address', 'latitude', 'longitude', 'status'])
            ->whereBetween('longitude', [$filters['west'], $filters['east']])->whereBetween('latitude', [$filters['south'], $filters['north']]);
        if (isset($filters['delivery_id'])) {
            $deliveryId = Delivery::query()->where('public_id', $filters['delivery_id'])->value('id');
            $inDelivery = fn ($q) => $q->where('delivery_id', $deliveryId);
            $delivered = fn ($q) => $q->where('delivery_id', $deliveryId)->where('status', 'delivered');
            $paid = fn ($q) => $q->where('delivery_id', $deliveryId)->where('type', 'payment');
            $query->withExists(['stops as visited_in_delivery' => $inDelivery, 'stops as delivered_in_delivery' => $delivered, 'movements as paid_in_delivery' => $paid]);
            match ($filters['visit_status'] ?? null) {
                'delivered' => $query->whereHas('stops', $delivered),
                'paid' => $query->whereHas('movements', $paid),
                'visited' => $query->where(fn ($q) => $q->whereHas('stops', $inDelivery)->orWhereHas('movements', $paid)),
                'pending' => $query->whereDoesntHave('stops', $inDelivery)->whereDoesntHave('movements', $paid),
                default => null,
            };
        }
        $limit = $filters['limit'] ?? 200;
        $rows = $query->limit($limit + 1)->get();

        return ['data' => $rows->take($limit)->map(fn (Customer $customer) => [
            'id' => $customer->public_id, 'name' => $customer->displayName(), 'address' => $customer->address,
            'latitude' => $customer->latitude, 'longitude' => $customer->longitude, 'status' => $customer->status->value,
            'visit_status' => $customer->delivered_in_delivery ? 'delivered' : ($customer->paid_in_delivery ? 'paid' : ($customer->visited_in_delivery ? 'visited' : 'pending')),
        ])->values()->all(), 'meta' => ['has_more' => $rows->count() > $limit, 'limit' => $limit]];
    }
}
