<?php

namespace App\Http\Requests\ManagementPlans;

use Illuminate\Validation\Validator;

final class AdjustMedicineStockRequest extends ManagementExecutionRequest
{
    public function authorize(): bool
    {
        return $this->authorizeMedicineStockManagement();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'quantity_delta' => ['required', 'integer', 'between:-2147483647,2147483647'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ((int) $this->input('quantity_delta') === 0 && $this->exists('quantity_delta')) {
                $validator->errors()->add('quantity_delta', 'El ajuste debe modificar el saldo.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        if (isset($data['quantity_delta'])) {
            $data['quantity_delta'] = (int) $data['quantity_delta'];
        }

        return $data;
    }
}
