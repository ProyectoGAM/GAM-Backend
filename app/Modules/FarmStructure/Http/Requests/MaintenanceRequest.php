<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\Maintenance;
use App\Models\User;
use App\Shared\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

abstract class MaintenanceRequest extends FormRequest
{
    protected function authorizeMaintenance(string $ability): bool
    {
        $actor = $this->user();
        $maintenance = $this->route('maintenance');

        return $actor instanceof User && $maintenance instanceof Maintenance
            && $actor->can($ability, $maintenance);
    }

    /** @return array<string, array<int, mixed>> */
    protected function maintenanceRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'maintenance_date' => [$required, 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => [$required, 'required', 'string', 'max:5000'],
            'cost_amount' => [$required, 'required', 'string', 'regex:/^\d{1,15}(?:\.\d{1,4})?$/D'],
            'cost_currency' => [$required, 'required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'responsible_user_id' => [$required, 'required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'poultry_house_id' => ['prohibited'],
            'status' => ['prohibited'],
            'scheduled_for' => ['prohibited'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['cost_amount', 'cost_currency'])) {
                    return;
                }

                $maintenance = $this->route('maintenance');
                $amount = $this->input('cost_amount', $maintenance instanceof Maintenance ? $maintenance->cost_amount : null);
                $currency = $this->input('cost_currency', $maintenance instanceof Maintenance ? $maintenance->cost_currency : null);

                if (is_string($amount) && is_string($currency)) {
                    try {
                        Money::fromDecimal($amount, $currency);
                    } catch (InvalidArgumentException $exception) {
                        $validator->errors()->add('cost_amount', $exception->getMessage());
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'maintenance_date.required' => 'Debes indicar la fecha del mantenimiento.',
            'maintenance_date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'maintenance_date.before_or_equal' => 'La fecha del mantenimiento no puede ser futura.',
            'description.required' => 'Debes indicar la descripción del mantenimiento.',
            'cost_amount.required' => 'Debes indicar el costo del mantenimiento.',
            'cost_amount.string' => 'El costo debe enviarse como texto decimal.',
            'cost_amount.regex' => 'El costo debe ser no negativo, con hasta 15 enteros y 4 decimales.',
            'cost_currency.required' => 'Debes indicar la moneda del costo.',
            'cost_currency.regex' => 'La moneda debe ser un código ISO de tres letras mayúsculas.',
            'responsible_user_id.required' => 'Debes indicar el responsable del mantenimiento.',
            'responsible_user_id.exists' => 'El responsable debe ser un usuario activo.',
            'reason.required' => 'Debes indicar el motivo de la operación.',
            'version.required' => 'Debes indicar la versión actual del mantenimiento.',
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'poultry_house_id.prohibited' => 'El galpón se define en la ruta y no puede cambiarse.',
            'status.prohibited' => 'El estado sólo puede cambiarse mediante la acción de cancelación.',
            'scheduled_for.prohibited' => 'No se permite programar mantenimientos futuros.',
        ];
    }
}
