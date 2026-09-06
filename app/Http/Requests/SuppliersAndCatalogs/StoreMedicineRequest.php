<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Medicine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreMedicineRequest extends FormRequest
{
    private bool $submittedIdempotencyKey = false;

    protected function prepareForValidation(): void
    {
        $this->submittedIdempotencyKey = $this->has('idempotency_key');
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Medicine::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['required', 'string', 'max:5000'],
            'proveedor_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->submittedIdempotencyKey) {
                $validator->errors()->add('idempotency_key', 'La clave de idempotencia sólo se admite en el encabezado Idempotency-Key.');
            }
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $validator->getRules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Debes indicar el nombre del medicamento.',
            'nombre.string' => 'El nombre debe ser texto.',
            'nombre.max' => 'El nombre no puede superar los 160 caracteres.',
            'descripcion.required' => 'Debes indicar la descripción del medicamento.',
            'descripcion.string' => 'La descripción debe ser texto.',
            'descripcion.max' => 'La descripción no puede superar los 5000 caracteres.',
            'proveedor_id.required' => 'Debes seleccionar un proveedor.',
            'proveedor_id.integer' => 'El proveedor debe ser un identificador entero.',
            'proveedor_id.min' => 'El proveedor debe ser un identificador positivo.',
            'proveedor_id.max' => 'El identificador del proveedor supera el límite permitido.',
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
        ];
    }
}
