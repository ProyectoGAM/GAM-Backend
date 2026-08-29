<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Models\SuppliersAndCatalogs\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('supplier')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'locality_id' => ['sometimes', 'nullable', 'integer', 'exists:localities,id'],
            'name' => ['sometimes', 'string', 'max:160'],
            'address' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $supplier = $this->route('supplier');
            if ($validator->errors()->has('name') || ! $supplier instanceof Supplier || ! $this->filled('name')) {
                return;
            }
            if (Supplier::query()->where('normalized_name', Str::lower(trim($this->string('name')->toString())))->whereKeyNot($supplier->getKey())->exists()) {
                $validator->errors()->add('name', 'El nombre del proveedor ya está registrado.');
            }
        }];
    }
}
