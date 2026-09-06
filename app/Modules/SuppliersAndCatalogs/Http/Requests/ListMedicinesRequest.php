<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Requests;

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
            'buscar' => ['sometimes', 'required', 'string', 'max:160'],
            'proveedor_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            'por_pagina' => ['sometimes', 'integer', 'between:1,100'],
            'pagina' => ['sometimes', 'integer', 'between:1,100000'],
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
        return ['buscar' => 'búsqueda', 'proveedor_id' => 'proveedor', 'por_pagina' => 'registros por página', 'pagina' => 'página'];
    }
}
