<?php

namespace App\Modules\Inventory\Http\Requests;

final class CancelEggStockTransactionRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.adjust') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(true), 'motivo_correccion' => ['required', 'string', 'max:500']];
    }
}
