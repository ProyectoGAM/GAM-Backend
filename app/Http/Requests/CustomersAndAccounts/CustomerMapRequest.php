<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\Deliveries\Delivery;

final class CustomerMapRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null || (! $actor->can('viewAny', Customer::class) && ! $actor->can('delivery.view-own'))) {
            return false;
        }
        if ($this->filled('delivery_id')) {
            $delivery = Delivery::query()->where('public_id', $this->input('delivery_id'))->first();
            if ($delivery !== null && ! $actor->can('delivery.history') && $delivery->driver_id !== $actor->id) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return ['west' => ['required', 'numeric', 'between:-180,180'], 'east' => ['required', 'numeric', 'between:-180,180', 'gt:west'],
            'south' => ['required', 'numeric', 'between:-90,90'], 'north' => ['required', 'numeric', 'between:-90,90', 'gt:south'],
            'limit' => ['sometimes', 'integer', 'between:1,200'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'], 'visit_status' => ['sometimes', 'in:pending,visited,delivered,paid'],
            'delivery_id' => ['required_with:visit_status', 'nullable', 'ulid', 'exists:deliveries,public_id']];
    }
}
