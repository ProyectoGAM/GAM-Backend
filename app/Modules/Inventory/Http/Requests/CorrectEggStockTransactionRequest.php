<?php

namespace App\Modules\Inventory\Http\Requests;

final class CorrectEggStockTransactionRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.adjust') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(true), 'cantidad' => ['sometimes', ...$this->quantityRules()], 'ocurrido_en' => ['sometimes', 'date'], 'motivo_correccion' => ['required', 'string', 'max:500'], 'motivo' => ['sometimes', 'nullable', 'string', 'max:500'], 'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }
}
