<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\StockBalance;
use Illuminate\Foundation\Http\FormRequest;

final class ListStockBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', StockBalance::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'stock_location_id' => ['sometimes', 'integer', 'exists:stock_locations,id'],
            'below_minimum' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
