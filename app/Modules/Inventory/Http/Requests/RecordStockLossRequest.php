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
            'lineas' => ['required', 'array', 'min:1', 'max:100'],
            'lineas.*.producto_id' => ['required', 'integer', 'exists:products,id'],
            'lineas.*.ubicacion_stock_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'lineas.*.cantidad' => ['required', 'string', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/'],
            'motivo' => ['required', 'string', 'max:255'],
            'ocurrido_en' => ['sometimes', 'date'],
        ];
    }
}
