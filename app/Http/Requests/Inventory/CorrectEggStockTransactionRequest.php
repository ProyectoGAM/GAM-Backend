<?php

namespace App\Http\Requests\Inventory;

final class CorrectEggStockTransactionRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.adjust') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(true), 'quantity' => ['sometimes', ...$this->quantityRules()], 'occurred_at' => ['sometimes', 'date'], 'correction_reason' => ['required', 'string', 'max:500'], 'reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }
}
