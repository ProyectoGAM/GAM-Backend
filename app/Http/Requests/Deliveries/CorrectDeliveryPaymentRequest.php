<?php

namespace App\Http\Requests\Deliveries;

final class CorrectDeliveryPaymentRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->can('can_update_own_deliveries') && $this->delivery()->driver_id === $actor->id;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'amount' => ['required', 'string', 'regex:/^\d{1,19}(?:\.\d{1,3})?$/D'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'amount.required' => 'Indicá el importe corregido. Usá cero para dejar el cobro sin efecto.',
            'amount.string' => 'Indicá un importe en UYU con hasta tres decimales.',
            'amount.regex' => 'Indicá un importe en UYU con hasta tres decimales.',
            'reason.required' => 'Indicá el motivo de la rectificación.',
            'reason.string' => 'El motivo de la rectificación debe ser texto.',
            'reason.max' => 'El motivo de la rectificación no puede superar los 2000 caracteres.',
        ];
    }
}
