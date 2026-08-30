<?php

namespace App\Modules\FarmStructure\Http\Requests;

use Illuminate\Validation\Validator;

final class UpdateMaintenanceRequest extends MaintenanceRequest
{
    public function authorize(): bool
    {
        return $this->authorizeMaintenance('update');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->maintenanceRules(true) + [
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (! $this->hasAny(['fecha_mantenimiento', 'descripcion', 'costo_importe', 'costo_moneda', 'responsable_id'])) {
                $validator->errors()->add('solicitud', 'Debes proporcionar al menos un campo para corregir.');
            }
        }];
    }
}
