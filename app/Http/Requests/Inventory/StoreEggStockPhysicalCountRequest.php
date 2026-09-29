<?php

namespace App\Http\Requests\Inventory;

final class StoreEggStockPhysicalCountRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.adjust') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'counted_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'expected_balance' => ['required', 'integer', 'min:-999999999999', 'max:999999999999'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'occurred_at' => ['required', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = parent::attributesForAction();
        $data['counted_quantity'] = (int) $data['counted_quantity'];
        $data['expected_balance'] = (int) $data['expected_balance'];

        return $data;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'counted_quantity.min' => 'El conteo físico no puede ser negativo.',
            'expected_balance.integer' => 'El saldo teórico esperado debe ser un número entero.',
            'reason.required' => 'El motivo del conteo es obligatorio.',
            'occurred_at.required' => 'La fecha del conteo es obligatoria.',
        ];
    }
}
