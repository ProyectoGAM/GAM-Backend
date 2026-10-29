<?php

namespace App\Http\Requests\Deliveries;

final class ListDeliveryClientsRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.view-own') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
