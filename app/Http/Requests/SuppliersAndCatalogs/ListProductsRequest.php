<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\SuppliersAndCatalogs\Product;
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
            'search' => ['sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::enum(ProductKind::class)],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
