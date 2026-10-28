<?php

namespace App\Http\Requests\ManagementPlans;

final class StoreVaccinationApplicationRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeFlockExecution();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...$this->activityLinkRules(),
            'vaccine_id' => ['required', 'ulid'],
            'vaccine_batch' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Futuro: el consumo físico queda reservado hasta habilitar sanidad por UP.
            'stock_consumption' => config('inventory.health_stock_enabled')
                ? ['sometimes', 'array:quantity,stock_location_id', 'required_array_keys:quantity,stock_location_id']
                : ['prohibited'],
            'stock_consumption.quantity' => ['required_with:stock_consumption', 'string', 'regex:/^(?=.*[1-9])\\d{1,12}(?:\\.\\d{1,6})?$/'],
            'stock_consumption.stock_location_id' => ['required_with:stock_consumption', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'stock_consumption.prohibited' => 'El stock físico de sanidad está deshabilitado. Registra la aplicación sin consumo de inventario.',
            'stock_consumption.quantity.regex' => 'La cantidad a descontar debe ser positiva y tener hasta seis decimales.',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        if (isset($data['responsible_user_id'])) {
            $data['responsible_user_id'] = (int) $data['responsible_user_id'];
        }
        if (isset($data['stock_consumption']['stock_location_id'])) {
            $data['stock_consumption']['stock_location_id'] = (int) $data['stock_consumption']['stock_location_id'];
        }

        return $data;
    }
}
