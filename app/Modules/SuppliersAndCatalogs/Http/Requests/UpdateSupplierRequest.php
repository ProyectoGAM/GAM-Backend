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
        return $this->user()?->can('update', $this->route('proveedor')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'localidad_id' => ['sometimes', 'nullable', 'integer', 'exists:localities,id'],
            'nombre' => ['sometimes', 'string', 'max:160'],
            'direccion' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $proveedor = $this->route('proveedor');
            if ($validator->errors()->has('nombre') || ! $proveedor instanceof Supplier || ! $this->filled('nombre')) {
                return;
            }
            if (Supplier::query()->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))->whereKeyNot($proveedor->getKey())->exists()) {
                $validator->errors()->add('nombre', 'El nombre del proveedor ya está registrado.');
            }
        }];
    }
}
