<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->routeIs('api.v1.vehicles.documents.download') ? 'can_view_vehicle_documents'
            : ($this->routeIs('api.v1.vehicles.fuel.index', 'api.v1.vehicles.fuel.receipt') ? 'can_view_vehicle_fuel'
                : ($this->routeIs('api.v1.vehicles.plans.index', 'api.v1.vehicles.maintenances.index', 'api.v1.vehicles.maintenances.documents.download') ? 'can_view_vehicle_maintenance'
                    : ($this->routeIs('api.v1.vehicles.usages.index') ? 'can_view_vehicles' : 'can_view_vehicles')));
        $user = $this->user();
        if ($this->routeIs('api.v1.vehicles.show')) {
            return collect(['can_view_vehicles', 'can_manage_vehicles', 'can_manage_vehicle_readings', 'can_use_vehicles', 'can_view_vehicle_fuel', 'can_manage_vehicle_fuel', 'can_view_vehicle_maintenance', 'can_manage_vehicle_maintenance', 'can_view_vehicle_documents', 'can_manage_vehicle_documents'])
                ->contains(fn (string $candidate): bool => $user?->can($candidate) === true);
        }

        return $user?->can($permission) === true
            || ($permission === 'can_view_vehicles' && $user?->can('can_manage_vehicles') === true)
            || ($permission === 'can_view_vehicle_fuel' && $user?->can('can_manage_vehicle_fuel') === true)
            || ($permission === 'can_view_vehicle_maintenance' && $user?->can('can_manage_vehicle_maintenance') === true)
            || ($permission === 'can_view_vehicle_documents' && $user?->can('can_manage_vehicle_documents') === true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
