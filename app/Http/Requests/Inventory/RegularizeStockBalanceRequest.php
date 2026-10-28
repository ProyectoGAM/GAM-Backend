<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\InventoryMovement;

final class RegularizeStockBalanceRequest extends InventoryCommandRequest
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
            'mode' => ['required', 'in:trasladar,recontar'],
            'reason' => ['required', 'string', 'max:255'],
            'to_stock_location_id' => ['required_if:mode,trasladar', 'prohibited_if:mode,recontar', 'integer', 'exists:stock_locations,id'],
            'quantity' => ['required_if:mode,trasladar', 'prohibited_if:mode,recontar', 'string', 'regex:/^(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?$/'],
        ];
    }
}
