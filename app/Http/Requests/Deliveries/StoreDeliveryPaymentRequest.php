<?php

namespace App\Http\Requests\Deliveries;

final class StoreDeliveryPaymentRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.update-own') && $this->delivery()->driver_id === $this->user()?->id;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), 'client_reference' => ['required', 'ulid'], 'client_event_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'], 'payment_method' => ['required', 'in:cash,transfer,other'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
