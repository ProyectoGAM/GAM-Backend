<?php

namespace App\Http\Requests\FarmStructure;

final class ViewMaintenanceRequest extends MaintenanceRequest
{
    public function authorize(): bool
    {
        return $this->authorizeMaintenance('view');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
