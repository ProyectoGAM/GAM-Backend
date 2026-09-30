<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ListVaccinesRequest extends FormRequest
{
    /**
     * Determina si el usuario puede listar vacunas.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Vaccine::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'required', 'string', 'min:1', 'max:160'],
            'sku' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],
            'proveedor_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            'estado' => ['sometimes', Rule::enum(ProductStatus::class)],
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
}
