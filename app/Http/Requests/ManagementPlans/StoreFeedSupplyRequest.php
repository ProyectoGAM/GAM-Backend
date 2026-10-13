<?php

namespace App\Http\Requests\ManagementPlans;

final class StoreFeedSupplyRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeFlockExecution();
    }

    public function rules(): array
    {
        if ($this->route('feedSupply') !== null) {
            return ['idempotency_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500']];
        }

        return ['idempotency_key' => ['required', 'uuid'],
            'occurred_at' => $this->activityLinkRules()['occurred_at'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'string', 'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/'],
            'unit' => ['required', 'in:kg,g'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['quantity.regex' => 'La cantidad debe ser positiva y tener hasta seis decimales.'];
    }

    public function attributesForAction(): array
    {
        $data = $this->validated();
        if (isset($data['product_id'])) {
            $data['product_id'] = (int) $data['product_id'];
        }

        return $data;
    }
}
