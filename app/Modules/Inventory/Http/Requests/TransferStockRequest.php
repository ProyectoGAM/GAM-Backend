<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\InventoryMovement;

final class TransferStockRequest extends InventoryCommandRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryMovement::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'lineas' => ['required', 'array', 'min:1', 'max:100'],
            'lineas.*.producto_id' => ['required', 'integer', 'exists:products,id'],
            'lineas.*.ubicacion_stock_origen_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'lineas.*.ubicacion_stock_destino_id' => ['required', 'integer', 'exists:stock_locations,id', 'different:lineas.*.ubicacion_stock_origen_id'],
            'lineas.*.cantidad' => ['required', 'string', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/'],
            'motivo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ocurrido_en' => ['sometimes', 'fecha'],
        ];
    }
}
