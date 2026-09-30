<?php

namespace App\Http\Requests\Inventory;

final class CancelEggStockTransactionRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.adjust') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(true), 'correction_reason' => ['required', 'string', 'max:500']];
    }
}
