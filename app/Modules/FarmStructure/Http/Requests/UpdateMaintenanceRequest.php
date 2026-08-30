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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (! $this->hasAny(['maintenance_date', 'description', 'cost_amount', 'cost_currency', 'responsible_user_id'])) {
                $validator->errors()->add('request', 'Debes proporcionar al menos un campo para corregir.');
            }
        }];
    }
}
