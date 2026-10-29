<?php

namespace App\Http\Requests\Inventory;

final class StoreEggDonationRequest extends EggStockRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('egg-stock.move') ?? false;
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'min:1', 'max:500'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }
}
