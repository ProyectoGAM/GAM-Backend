<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Flock;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class LotsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->isMethod('GET')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
        if ($this->route('poultryHouse') !== null) {
            $this->merge(['poultry_house_id' => $this->route('poultryHouse')]);
        }
    }

    protected function flockAbility(string $ability): bool
    {
        $flock = $this->route('flock');

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
        $data = $this->validated();
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
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
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
            'quantity.min' => 'La cantidad debe ser mayor que cero.',
            'initial_quantity.min' => 'La cantidad inicial debe ser mayor que cero.',
            'version.required' => 'Debes indicar la versión actual del registro.',
            'occurred_at.regex' => 'La fecha debe incluir hora, segundos y zona horaria, por ejemplo 2026-08-30T12:00:00-03:00.',
        ];
    }
}
