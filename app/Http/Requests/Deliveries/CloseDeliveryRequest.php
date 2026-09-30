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
            'returned_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        unset($data['pin']);
        $data['returned_quantity'] = (int) $data['returned_quantity'];

        return $data;
    }
}
