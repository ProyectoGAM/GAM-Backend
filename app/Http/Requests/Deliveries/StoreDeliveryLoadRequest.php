<?php

namespace App\Http\Requests\Deliveries;

use Illuminate\Validation\Rule;

final class StoreDeliveryLoadRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.update-own')
            && $this->delivery()->driver_id === $this->user()?->getKey();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'quantity' => ['required_without:items', 'prohibits:items', 'integer', 'min:1', 'max:2147483647'],
            'items' => ['required_without:quantity', 'prohibits:quantity', 'array', 'min:1', 'max:20'],
            'items.*' => ['array:unit,amount,eggs_per_unit'],
            'items.*.unit' => ['required', Rule::in(array_keys(config('delivery_units')))],
            'items.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'items.*.eggs_per_unit' => ['required', 'integer', 'min:1'],
        ];
    }
}
