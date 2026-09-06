<?php

namespace App\Http\Requests\Inventory;

final class StoreEggStockIssueRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.move') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(), 'quantity' => $this->quantityRules(), 'type' => ['required', 'in:distribution_preparation,loss'], 'occurred_at' => ['sometimes', 'date'], 'reason' => ['required', 'string', 'max:500'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }
}
