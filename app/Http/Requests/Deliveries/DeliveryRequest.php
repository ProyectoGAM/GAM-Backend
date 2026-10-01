<?php

namespace App\Http\Requests\Deliveries;

use App\Models\Deliveries\Delivery;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class DeliveryRequest extends FormRequest
{
    public function actor(): User
    {
        /** @var User $actor */
        $actor = $this->user();

        return $actor;
    }

    public function delivery(): Delivery
    {
        /** @var Delivery $delivery */
        $delivery = $this->route('reparto');

        return $delivery;
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
        return $this->validated();
    }

    /** @return array<string, list<string>> */
    protected function commandRules(): array
    {
        return ['idempotency_key' => ['required', 'uuid']];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function ($validator): void {
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
        ];
    }
}
