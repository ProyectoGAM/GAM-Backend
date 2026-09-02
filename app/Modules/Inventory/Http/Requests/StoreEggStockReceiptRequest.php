<?php

namespace App\Modules\Inventory\Http\Requests;

final class StoreEggStockReceiptRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.move') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(), 'cantidad' => $this->quantityRules(), 'ocurrido_en' => ['sometimes', 'date'], 'motivo' => ['required', 'string', 'max:500'], 'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }
}
