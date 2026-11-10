<?php

namespace App\Http\Requests\Deliveries;

use App\Models\CustomersAndAccounts\Customer;

final class CorrectDeliveryStopRequest extends DeliveryRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && ! $actor->usesDevicePin() && $actor->can('adjust', Customer::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'motivo' => ['required', 'string', 'max:2000'],
            'lineas' => ['required_without:cantidad_entregada', 'prohibits:cantidad_entregada,importe', 'array', 'list', 'min:1', 'max:20'],
            'lineas.*' => ['array:indice,cantidad'],
            'lineas.*.indice' => ['required', 'integer', 'min:0', 'max:19', 'distinct'],
            'lineas.*.cantidad' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,3})?$/D'],
            'cantidad_entregada' => ['required_without:lineas', 'prohibits:lineas', 'integer', 'min:0', 'max:2147483647'],
            'importe' => ['sometimes', 'string', 'regex:/^\d{1,19}(?:\.\d{1,3})?$/D'],
            'return_production_unit_id' => ['sometimes', 'integer', 'min:1', 'exists:production_units,id'],
        ];
    }
}
