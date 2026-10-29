<?php

namespace App\Http\Requests\ManagementPlans;

final class StoreMedicineApplicationRequest extends ManagementExecutionRequest
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
            'medicine_id' => ['required', 'ulid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Futuro: el consumo físico queda reservado hasta habilitar sanidad por UP.
            'stock_consumption' => config('inventory.health_stock_enabled')
                ? ['sometimes', 'array:quantity,stock_location_id', 'required_array_keys:quantity,stock_location_id']
                : ['prohibited'],
            'stock_consumption.quantity' => ['required_with:stock_consumption', 'string', 'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/'],
            'stock_consumption.stock_location_id' => ['required_with:stock_consumption', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'stock_consumption.prohibited' => 'El stock físico de sanidad está deshabilitado. Registra la aplicación sin consumo de inventario.',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        foreach (['responsible_user_id', 'quantity'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }
}
