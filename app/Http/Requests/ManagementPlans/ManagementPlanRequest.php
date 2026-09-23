<?php

namespace App\Http\Requests\ManagementPlans;

use App\Models\User;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ManagementPlanRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->isMethod('GET')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    protected function allowed(string $permission): bool
    {
        $user = $this->user();

        return $user instanceof User && LotsAuthorization::allows($user, $permission);
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
        return $this->validated();
    }

    /** @return array<string, mixed> */
    protected function activityRules(): array
    {
        return [
            'activities' => ['required', 'array', 'min:1', 'max:100'],
            'activities.*' => ['required', 'array:type,title,timing_kind,start_day,end_day,start_week,end_week,interval_days,conditional,condition,notes,catalog_ref'],
            'activities.*.type' => ['required', 'in:vaccination,medication,ration_change,weighing,manual_practice,flock_movement,egg_collection,mortality'],
            'activities.*.title' => ['required', 'string', 'max:200'],
            'activities.*.timing_kind' => ['required', 'in:day,week,week_range,day_recurrence,unscheduled'],
            'activities.*.start_day' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'activities.*.end_day' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'activities.*.start_week' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'activities.*.end_week' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'activities.*.interval_days' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'activities.*.conditional' => ['sometimes', 'boolean'],
            'activities.*.condition' => ['nullable', 'string', 'max:1000'],
            'activities.*.notes' => ['nullable', 'string', 'max:5000'],
            'activities.*.catalog_ref' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 64) {
                    $fail('La referencia de catálogo debe ser un identificador válido.');
                }
            }],
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
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'activities.required' => 'Debes indicar las actividades del plan.',
            'activities.*.title.required' => 'Cada actividad necesita una descripción.',
        ];
    }
}
