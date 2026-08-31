<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

use App\Models\SuppliersAndCatalogs\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Supplier::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'localidad_id' => ['sometimes', 'nullable', 'integer', 'exists:localities,id'],
            'nombre' => ['required', 'string', 'max:160'],
            'direccion' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('nombre')) {
                return;
            }
            if (Supplier::query()->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))->exists()) {
                $validator->errors()->add('nombre', 'El nombre del proveedor ya está registrado.');
            }
        }];
    }
}
