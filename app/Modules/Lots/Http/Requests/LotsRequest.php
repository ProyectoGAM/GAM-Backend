<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\Flock;
use App\Models\User;
use App\Support\PublicInputMapper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class LotsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->isMethod('GET')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
        if ($this->route('galpon') !== null) {
            $this->merge(['galpon_id' => $this->route('galpon')]);
        }
    }

    protected function flockAbility(string $ability): bool
    {
        $flock = $this->route('lote');

        return $this->user()?->can($ability, $flock instanceof Flock ? $flock : Flock::class) ?? false;
    }

    public function actor(): User
    {
        /** @var User $actor */
        $actor = $this->user();

        return $actor;
    }

    /** @return array<string, mixed> */
    public function attributesForAction(): array
    {
        $data = PublicInputMapper::toInternal($this->validated(), 'lots');
        foreach (['initial_quantity', 'quantity', 'version', 'destination_version', 'flock_version', 'breed_id', 'supplier_id', 'poultry_house_id', 'destination_poultry_house_id', 'mortality_category_id', 'product_id', 'stock_location_id', 'production_unit_id', 'page', 'per_page'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        if (isset($data['lines']) && is_array($data['lines'])) {
            $data['lines'] = array_map(static function (array $line): array {
                foreach (['product_id', 'stock_location_id', 'quantity'] as $key) {
                    if (isset($line[$key])) {
                        $line[$key] = (int) $line[$key];
                    }
                }

                return $line;
            }, $data['lines']);
        }

        return $data;
    }

    /** @return array<string, list<string>> */
    protected function commandRules(bool $version = true): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...($version ? ['version' => ['required', 'integer', 'min:1', 'max:2147483647']] : []),
        ];
    }

    /** @return array<string, list<string>> */
    protected function filterRules(): array
    {
        return [
            'pagina' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'fecha_desde' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('fecha_desde') ? ['after_or_equal:fecha_desde'] : [])],
        ];
    }

    /** @return list<string> */
    protected function quantityRules(): array
    {
        return ['required', 'integer', 'min:1', 'max:2147483647'];
    }

    /** @return list<string> */
    protected function timeRules(): array
    {
        return ['sometimes', 'date', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(Z|[+-]\\d{2}:\\d{2})$/'];
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
            'cantidad_inicial.min' => 'La cantidad inicial debe ser mayor que cero.',
            'version.required' => 'Debes indicar la versión actual del registro.',
            'ocurrido_en.regex' => 'La fecha debe incluir hora, segundos y zona horaria, por ejemplo 2026-08-30T12:00:00-03:00.',
        ];
    }
}
