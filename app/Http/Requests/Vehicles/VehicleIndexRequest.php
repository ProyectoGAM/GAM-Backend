<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return collect(['vehicles.view', 'vehicles.manage', 'vehicles.readings.manage', 'vehicles.use', 'vehicles.fuel.view', 'vehicles.fuel.manage', 'vehicles.maintenance.view', 'vehicles.maintenance.manage', 'vehicles.documents.view', 'vehicles.documents.manage'])
            ->contains(fn (string $permission): bool => $user?->can($permission) === true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:120'], 'status' => ['sometimes', 'in:active,out_of_service,retired'], 'availability' => ['sometimes', 'in:available,in_use,not_available'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
