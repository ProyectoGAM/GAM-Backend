<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Medicine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ListMedicinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Medicine::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'required', 'string', 'max:160'],
            'supplier_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $validator->getRules())) {
                    $validator->errors()->add($key, 'El filtro no está permitido en esta consulta.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['search' => 'búsqueda', 'supplier_id' => 'proveedor', 'per_page' => 'registros por página', 'page' => 'página'];
    }
}
