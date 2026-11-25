<?php

namespace App\Http\Requests\Deliveries;

final class StoreDeliveryPaymentRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->can('can_update_own_deliveries') && $this->delivery()->driver_id === $actor->id;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), 'client_reference' => ['required', 'ulid'], 'client_event_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'], 'payment_method' => ['required', 'in:cash,transfer,check,other'],
            'delivery_stop_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'occurred_at' => ['sometimes', 'date'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['delivery_stop_id' => 'identificador de entrega'];
    }
}
