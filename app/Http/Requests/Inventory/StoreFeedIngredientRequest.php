<?php

namespace App\Http\Requests\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreFeedIngredientRequest extends FormRequest
{
    private bool $submittedIdempotencyKey = false;

    protected function prepareForValidation(): void
    {
        $this->submittedIdempotencyKey = $this->has('idempotency_key');
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
            'unidad' => $this->input('unidad', 'g'),
        ]);
    }

    /** Determina si el usuario puede realizar esta operación. */
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class)
            && $this->user()->can('create', InventoryMovement::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:80'],
            'nombre' => ['required', 'string', 'max:160'],
            'cantidad' => ['required', 'string', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/'],
            'unidad' => ['required', Rule::in(['g', 'kg'])],
            'proveedor_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->submittedIdempotencyKey) {
                $validator->errors()->add('idempotency_key', 'La clave de idempotencia sólo se admite en el encabezado Idempotency-Key.');
            }

            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $this->rules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'cantidad.regex' => 'La cantidad debe ser positiva y tener hasta seis decimales.',
            'unidad.in' => 'La unidad debe ser g o kg.',
        ];
    }
}
