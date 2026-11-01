<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->routeIs('api.v1.vehicles.documents.download') ? 'vehicles.documents.view'
            : ($this->routeIs('api.v1.vehicles.fuel.index', 'api.v1.vehicles.fuel.receipt') ? 'vehicles.fuel.view'
                : ($this->routeIs('api.v1.vehicles.plans.index', 'api.v1.vehicles.maintenances.index', 'api.v1.vehicles.maintenances.documents.download') ? 'vehicles.maintenance.view'
                    : ($this->routeIs('api.v1.vehicles.usages.index') ? 'vehicles.view' : 'vehicles.view')));
        $user = $this->user();
        if ($this->routeIs('api.v1.vehicles.show')) {
            return collect(['vehicles.view', 'vehicles.manage', 'vehicles.readings.manage', 'vehicles.use', 'vehicles.fuel.view', 'vehicles.fuel.manage', 'vehicles.maintenance.view', 'vehicles.maintenance.manage', 'vehicles.documents.view', 'vehicles.documents.manage'])
                ->contains(fn (string $candidate): bool => $user?->can($candidate) === true);
        }

        return $user?->can($permission) === true
            || ($permission === 'vehicles.view' && $user?->can('vehicles.manage') === true)
            || ($permission === 'vehicles.fuel.view' && $user?->can('vehicles.fuel.manage') === true)
            || ($permission === 'vehicles.maintenance.view' && $user?->can('vehicles.maintenance.manage') === true)
            || ($permission === 'vehicles.documents.view' && $user?->can('vehicles.documents.manage') === true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
