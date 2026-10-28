<?php

namespace App\Http\Requests\Inventory;

use App\Enums\Inventory\StockLocationStatus;
use App\Enums\Inventory\StockLocationType;
use App\Models\Inventory\StockLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListStockLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', StockLocation::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(StockLocationStatus::class)],
            'type' => ['sometimes', Rule::enum(StockLocationType::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'production_unit_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
