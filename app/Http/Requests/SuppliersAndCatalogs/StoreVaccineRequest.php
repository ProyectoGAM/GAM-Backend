<?php

namespace App\Http\Requests\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreVaccineRequest extends FormRequest
{
    private bool $submittedIdempotencyKey = false;

    protected function prepareForValidation(): void
    {
        $this->submittedIdempotencyKey = $this->has('idempotency_key');
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Vaccine::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'min:1', 'max:80'],
            'nombre' => ['required', 'string', 'min:1', 'max:160'],
            'descripcion' => ['required', 'string', 'min:1', 'max:5000'],
            'detalles' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'proveedor_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'unidad_base' => ['sometimes', 'nullable', Rule::enum(BaseUnit::class)],
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
            if (! $validator->errors()->hasAny(['sku', 'nombre'])
                && ! Product::query()->where('sku', trim($this->string('sku')->toString()))->exists()
                && Product::query()->where('normalized_name', Str::lower(trim($this->string('nombre')->toString())))->exists()) {
                $validator->errors()->add('nombre', 'El nombre del producto ya está registrado.');
            }
        }];
    }
}
