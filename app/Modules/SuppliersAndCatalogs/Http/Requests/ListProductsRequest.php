<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Product::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'string', 'max:120'],
            'tipo' => ['sometimes', Rule::enum(ProductKind::class)],
            'estado' => ['sometimes', Rule::enum(ProductStatus::class)],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
