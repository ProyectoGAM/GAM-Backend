<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\Inventory\StockLocation;
use App\Modules\Inventory\Domain\Enums\StockLocationStatus;
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
            'buscar' => ['sometimes', 'string', 'max:120'],
            'estado' => ['sometimes', Rule::enum(StockLocationStatus::class)],
            'unidad_productiva_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
