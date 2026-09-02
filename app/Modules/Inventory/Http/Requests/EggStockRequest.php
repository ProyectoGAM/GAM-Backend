<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Models\User;
use App\Support\PublicInputMapper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class EggStockRequest extends FormRequest
{
    public function actor(): User
    {
        /** @var User $actor */
        $actor = $this->user();

        return $actor;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isMethod('GET')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = PublicInputMapper::toInternal($this->validated(), 'egg-stock');
        foreach (['quantity', 'version', 'production_unit_id', 'page', 'per_page'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }

    /** @return array<string, list<string>> */
    protected function commandRules(bool $version = false): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...($version ? ['version' => ['required', 'integer', 'min:1', 'max:2147483647']] : []),
        ];
    }

    /** @return list<string> */
    protected function quantityRules(): array
    {
        return ['required', 'integer', 'min:1', 'max:2147483647'];
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
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'cantidad.min' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
