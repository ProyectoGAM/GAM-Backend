<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class InventoryCommandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'lineas.required' => 'Debes indicar al menos una línea.',
            'lineas.min' => 'Debes indicar al menos una línea.',
            'lineas.max' => 'No puedes enviar más de 100 líneas.',
            'lineas.*.cantidad.regex' => 'La cantidad debe tener hasta seis decimales y ser positiva.',
            'lineas.*.cantidad_contada.regex' => 'La cantidad contada debe tener hasta seis decimales y no ser negativa.',
        ];
    }
}
