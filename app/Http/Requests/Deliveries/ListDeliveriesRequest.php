<?php

namespace App\Http\Requests\Deliveries;

use App\Enums\Deliveries\DeliveryStatus;
use Illuminate\Validation\Rule;

final class ListDeliveriesRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.history') || $this->user()?->can('delivery.monitor')
            ? true
            : false;
    }

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
            'driver_id' => ['sometimes', 'integer', 'exists:users,id'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = $this->validated();
        foreach (['driver_id', 'per_page'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }
}
