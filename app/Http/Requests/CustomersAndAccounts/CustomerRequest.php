<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Services\CustomersAndAccounts\CustomerFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class CustomerRequest extends FormRequest
{
    private bool $submittedKey = false;

    protected function prepareForValidation(): void
    {
        $data = CustomerFields::normalize($this->all());
        if (! $this->isMethod('GET')) {
            $this->submittedKey = array_key_exists('idempotency_key', $data);
            $data['idempotency_key'] = $this->header('Idempotency-Key');
        }
        $this->merge($data);
    }

    protected function commandRules(): array
    {
        return ['idempotency_key' => ['required', 'uuid']];
    }

    public function key(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    public function payload(): array
    {
        return $this->safe()->except(['idempotency_key']);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->submittedKey) {
                $validator->errors()->add('idempotency_key', 'La clave de idempotencia sólo se admite en el encabezado Idempotency-Key.');
            }
            foreach (array_keys($this->all()) as $key) {
                if (! array_key_exists($key, $validator->getRules())) {
                    $validator->errors()->add($key, 'El campo no está permitido en esta operación.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.'];
    }
}
