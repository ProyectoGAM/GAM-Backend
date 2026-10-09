<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEggPresentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'eggs_per_unit' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'default_unit_price' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $validator->getRules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'eggs_per_unit' => 'huevos por unidad', 'default_unit_price' => 'precio predeterminado'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['default_unit_price.integer' => 'El precio predeterminado debe expresarse en pesos uruguayos enteros.'];
    }
}
