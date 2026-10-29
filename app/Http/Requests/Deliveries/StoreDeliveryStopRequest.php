<?php

namespace App\Http\Requests\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use Illuminate\Validation\Rule;

final class StoreDeliveryStopRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.update-own')
            && $this->delivery()->driver_id === $this->user()?->getKey();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'items.*.unit_price.integer' => 'El precio unitario debe expresarse en pesos uruguayos enteros.'];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'accounting_version' => ['sometimes', 'integer', 'in:2'],
            'client_event_id' => ['sometimes', 'uuid'],
            'payment' => ['sometimes', 'prohibited_if:status,not_delivered', 'array:amount,payment_method,payment_reference,reason'],
            'payment.amount' => ['required_with:payment', 'string'],
            'payment.payment_method' => ['sometimes', 'in:cash,transfer,other'],
            'payment.payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'],
            'payment.reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_reference' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::in([DeliveryStopStatus::Delivered->value, DeliveryStopStatus::NotDelivered->value])],
            'items' => ['required_if:status,delivered', 'prohibited_if:status,not_delivered', 'array', 'min:1', 'max:20'],
            'items.*' => ['array:unit,amount,eggs_per_unit,unit_price'],
            'items.*.unit' => ['required', 'string', 'max:80'],
            'items.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'items.*.unit_price' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'items.*.eggs_per_unit' => ['required', 'integer', 'min:1'],
            'visit_reason' => ['sometimes', 'nullable', 'string', 'max:240'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
