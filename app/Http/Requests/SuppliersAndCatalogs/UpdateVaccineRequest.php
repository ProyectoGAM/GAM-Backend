<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateVaccineRequest extends FormRequest
{
    /**
     * Determina si el usuario puede actualizar la vacuna.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('vaccine')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'min:1', 'max:160'],
            'descripcion' => ['sometimes', 'string', 'min:1', 'max:5000'],
            'detalles' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'proveedor_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('body', 'Debes indicar al menos un campo para actualizar.');
            }
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $validator->getRules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
            $vaccine = $this->route('vaccine');
            if ($vaccine instanceof Vaccine && ! $validator->errors()->has('nombre') && $this->filled('nombre') && Product::query()
                ->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))
                ->whereKeyNot($vaccine->product_id)
                ->exists()) {
                $validator->errors()->add('nombre', 'El nombre del producto ya está registrado.');
            }
        }];
    }
}
