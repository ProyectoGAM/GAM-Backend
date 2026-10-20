<?php

namespace App\Http\Requests\Deliveries;

final class CloseDeliveryRequest extends DeliveryRequest
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
            'pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'],
            'returned_quantity' => ['required_without:returned_items', 'prohibits:returned_items', 'integer', 'min:0', 'max:2147483647'],
            'returned_items' => ['required_without:returned_quantity', 'prohibits:returned_quantity', 'array', 'max:20'],
            'returned_items.*' => ['array:unit,amount,eggs_per_unit'],
            'returned_items.*.unit' => ['required', 'string', 'max:80'],
            'returned_items.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'returned_items.*.eggs_per_unit' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'return_production_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:production_units,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        unset($data['pin']);
        if (isset($data['returned_quantity'])) {
            $data['returned_quantity'] = (int) $data['returned_quantity'];
        }

        return $data;
    }
}
