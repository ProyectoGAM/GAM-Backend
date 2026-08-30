<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\InventoryMovement;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListInventoryMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', InventoryMovement::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tipo' => ['sometimes', Rule::enum(InventoryMovementType::class)],
            'producto_id' => ['sometimes', 'integer', 'exists:products,id'],
            'ubicacion_stock_id' => ['sometimes', 'integer', 'exists:stock_locations,id'],
            'proveedor_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
