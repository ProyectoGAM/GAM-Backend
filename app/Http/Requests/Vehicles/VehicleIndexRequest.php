<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return collect(['can_view_vehicles', 'can_manage_vehicles', 'can_manage_vehicle_readings', 'can_use_vehicles', 'can_view_vehicle_fuel', 'can_manage_vehicle_fuel', 'can_view_vehicle_maintenance', 'can_manage_vehicle_maintenance', 'can_view_vehicle_documents', 'can_manage_vehicle_documents'])
            ->contains(fn (string $permission): bool => $user?->can($permission) === true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:120'], 'status' => ['sometimes', 'in:active,out_of_service,retired'], 'availability' => ['sometimes', 'in:available,in_use,not_available'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
