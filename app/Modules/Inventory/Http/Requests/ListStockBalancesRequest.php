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
            'producto_id' => ['sometimes', 'integer', 'exists:products,id'],
            'ubicacion_stock_id' => ['sometimes', 'integer', 'exists:stock_locations,id'],
            'bajo_minimo' => ['sometimes', 'boolean'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
