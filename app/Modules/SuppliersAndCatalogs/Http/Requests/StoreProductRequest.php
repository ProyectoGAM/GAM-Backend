<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::enum(ProductKind::class)],
            'base_unit' => ['required', Rule::enum(BaseUnit::class)],
            'stock_tracked' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['sku', 'name'])) {
                return;
            }
            if (Product::query()->where('sku', trim($this->string('sku')->toString()))->exists()) {
                $validator->errors()->add('sku', 'El SKU ya está registrado.');
            }
            if (Product::query()->where('normalized_name', Str::lower(trim($this->string('name')->toString())))->exists()) {
                $validator->errors()->add('name', 'El nombre del producto ya está registrado.');
            }
        }];
    }
}
