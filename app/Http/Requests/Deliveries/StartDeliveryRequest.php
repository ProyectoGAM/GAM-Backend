<?php

namespace App\Http\Requests\Deliveries;

use Illuminate\Validation\Rule;

final class StartDeliveryRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.start') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'],
            'production_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:production_units,id'],
            'quantity' => ['required_without:items', 'prohibits:items', 'integer', 'min:1', 'max:2147483647'],
            'items' => ['required_without:quantity', 'prohibits:quantity', 'array', 'min:1', 'max:20'],
            'items.*' => ['array:unit,amount,eggs_per_unit'],
            'items.*.unit' => ['required', Rule::in(array_keys(config('delivery_units')))],
            'items.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'items.*.eggs_per_unit' => ['required', 'integer', 'min:1'],
            'vehicle_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        unset($data['pin']);
        if (isset($data['production_unit_id'])) {
            $data['production_unit_id'] = (int) $data['production_unit_id'];
        }
        if (isset($data['quantity'])) {
            $data['quantity'] = (int) $data['quantity'];
        }

        return $data;
    }
}
