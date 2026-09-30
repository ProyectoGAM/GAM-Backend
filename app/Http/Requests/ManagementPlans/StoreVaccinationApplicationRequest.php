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
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'stock_consumption' => ['sometimes', 'array'],
            'stock_consumption.quantity' => ['required_with:stock_consumption', 'string', 'regex:/^(?=.*[1-9])\\d{1,12}(?:\\.\\d{1,6})?$/'],
            'stock_consumption.stock_location_id' => ['required_with:stock_consumption', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['stock_consumption.quantity.regex' => 'La cantidad a descontar debe ser positiva y tener hasta seis decimales.'];
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
