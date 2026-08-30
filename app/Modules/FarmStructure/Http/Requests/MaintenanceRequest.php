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
            'fecha_mantenimiento' => [$required, 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'descripcion' => [$required, 'required', 'string', 'max:5000'],
            'costo_importe' => [$required, 'required', 'string', 'regex:/^\d{1,15}(?:\.\d{1,4})?$/D'],
            'costo_moneda' => [$required, 'required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'responsable_id' => [$required, 'required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'galpon_id' => ['prohibited'],
            'estado' => ['prohibited'],
            'programado_para' => ['prohibited'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['costo_importe', 'costo_moneda']) || $validator->errors()->hasAny(['costo_importe', 'costo_moneda'])) {
                    return;
                }

                $maintenance = $this->route('maintenance');
                $amount = $this->input('costo_importe', $maintenance instanceof Maintenance ? $maintenance->cost_amount : null);
                $currency = $this->input('costo_moneda', $maintenance instanceof Maintenance ? $maintenance->cost_currency : null);

                if (is_string($amount) && is_string($currency)) {
                    try {
                        Money::fromDecimal($amount, $currency);
                    } catch (InvalidArgumentException $exception) {
                        $validator->errors()->add('costo_importe', $exception->getMessage());
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha_mantenimiento.required' => 'Debes indicar la fecha del mantenimiento.',
            'fecha_mantenimiento.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha_mantenimiento.before_or_equal' => 'La fecha del mantenimiento no puede ser futura.',
            'descripcion.required' => 'Debes indicar la descripción del mantenimiento.',
            'costo_importe.required' => 'Debes indicar el costo del mantenimiento.',
            'costo_importe.string' => 'El costo debe enviarse como texto decimal.',
            'costo_importe.regex' => 'El costo debe ser no negativo, con hasta 15 enteros y 4 decimales.',
            'costo_moneda.required' => 'Debes indicar la moneda del costo.',
            'costo_moneda.regex' => 'La moneda debe ser un código ISO de tres letras mayúsculas.',
            'responsable_id.required' => 'Debes indicar el responsable del mantenimiento.',
            'responsable_id.exists' => 'El responsable debe ser un usuario activo.',
            'motivo.required' => 'Debes indicar el motivo de la operación.',
            'version.required' => 'Debes indicar la versión actual del mantenimiento.',
            'idempotency_key.required' => 'El encabezado Idempotency-Key es obligatorio.',
            'idempotency_key.uuid' => 'El encabezado Idempotency-Key debe ser un UUID válido.',
            'galpon_id.prohibited' => 'El galpón se define en la ruta y no puede cambiarse.',
            'estado.prohibited' => 'El estado sólo puede cambiarse mediante la acción de cancelación.',
            'programado_para.prohibited' => 'No se permite programar mantenimientos futuros.',
        ];
    }
}
