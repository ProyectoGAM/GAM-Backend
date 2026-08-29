<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\InventoryMovement;

final class RecordStockLossRequest extends InventoryCommandRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', InventoryMovement::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.stock_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'max:255'],
            'occurred_at' => ['sometimes', 'date'],
        ];
    }
}
