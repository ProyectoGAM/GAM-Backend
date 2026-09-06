<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Models\SuppliersAndCatalogs\Product;
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
            'nombre' => ['required', 'string', 'max:160'],
            'tipo' => ['required', Rule::enum(ProductKind::class)],
            'unidad_base' => ['required', Rule::enum(BaseUnit::class)],
            'controla_stock' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['sku', 'nombre'])) {
                return;
            }
            if (Product::query()->where('sku', trim($this->string('sku')->toString()))->exists()) {
                $validator->errors()->add('sku', 'El SKU ya está registrado.');
            }
            if (Product::query()->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))->exists()) {
                $validator->errors()->add('nombre', 'El nombre del producto ya está registrado.');
            }
        }];
    }
}
