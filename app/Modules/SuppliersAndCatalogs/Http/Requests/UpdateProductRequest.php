<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:80'],
            'name' => ['sometimes', 'string', 'max:160'],
            'kind' => ['sometimes', 'string', 'in:raw_material,supply,finished_feed,egg,medicine,vaccine,other'],
            'base_unit' => ['sometimes', Rule::enum(BaseUnit::class)],
            'stock_tracked' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $product = $this->route('product');
            if (! $product instanceof Product) {
                return;
            }
            if ($this->filled('sku') && Product::query()->where('sku', trim($this->string('sku')->toString()))->whereKeyNot($product->getKey())->exists()) {
                $validator->errors()->add('sku', 'El SKU ya está registrado.');
            }
            if ($this->filled('name') && Product::query()->where('normalized_name', Str::lower(trim($this->string('name')->toString())))->whereKeyNot($product->getKey())->exists()) {
                $validator->errors()->add('name', 'El nombre del producto ya está registrado.');
            }
        }];
    }
}
