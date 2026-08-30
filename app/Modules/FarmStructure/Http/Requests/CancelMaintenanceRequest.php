<?php

namespace App\Modules\FarmStructure\Http\Requests;

final class CancelMaintenanceRequest extends MaintenanceRequest
{
    public function authorize(): bool
    {
        return $this->authorizeMaintenance('cancel');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
