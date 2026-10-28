<?php

namespace App\Http\Requests\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAllowedStockProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', InventoryMovement::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'stock_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'operation' => ['required', Rule::enum(InventoryMovementType::class)->only([
                InventoryMovementType::Receipt,
                InventoryMovementType::Issue,
                InventoryMovementType::Loss,
                InventoryMovementType::Adjustment,
                InventoryMovementType::Transfer,
            ])],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'stock_location_id' => 'ubicación',
            'operation' => 'operación',
            'search' => 'búsqueda',
            'per_page' => 'productos por página',
            'page' => 'página',
        ];
    }
}
