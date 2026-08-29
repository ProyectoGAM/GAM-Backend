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
            'type' => ['sometimes', Rule::enum(InventoryMovementType::class)],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'stock_location_id' => ['sometimes', 'integer', 'exists:stock_locations,id'],
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
