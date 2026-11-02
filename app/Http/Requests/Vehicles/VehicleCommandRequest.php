<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VehicleCommandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->isMethod('GET') && $this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function authorize(): bool
    {
        $permission = match (true) {
            $this->routeIs('api.v1.vehicles.readings.*') => 'can_manage_vehicle_readings',
            $this->routeIs('api.v1.vehicles.fuel.*') => 'can_manage_vehicle_fuel',
            $this->routeIs('api.v1.vehicles.plans.*', 'api.v1.vehicles.maintenances.*') => 'can_manage_vehicle_maintenance',
            $this->routeIs('api.v1.vehicles.documents.*') => 'can_manage_vehicle_documents',
            $this->routeIs('api.v1.vehicles.usages.*') => 'can_use_vehicles',
            default => 'can_manage_vehicles',
        };
        $user = $this->user();
        $authorized = $user?->can($permission) ?? false;
        if ($this->routeIs('api.v1.vehicles.fuel.store')) {
            $authorized = $authorized || ($user?->can('can_record_delivery_fuel') ?? false);
        }
        if ($this->routeIs('api.v1.vehicles.usages.*')) {
            $authorized = $authorized || ($user?->can('can_manage_vehicles') ?? false);
        }
        if (! $authorized) {
            return false;
        }
        if ($this->routeIs('api.v1.vehicles.usages.close') && ! $user->can('can_manage_vehicles')) {
            return $user->can('can_use_vehicles') && $this->route('uso')?->driver_id === $user->getKey();
        }

        return true;
    }

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        $rules = [
            'plate' => ['sometimes', 'required', 'string', 'max:24'], 'make' => ['sometimes', 'required', 'string', 'max:80'],
            'model' => ['sometimes', 'required', 'string', 'max:100'], 'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'admitted_at' => ['sometimes', 'nullable', 'date'], 'capacity_kg' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999'],
            'habitual_driver_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'version' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(['active', 'out_of_service', 'retired'])],
            'reason' => ['sometimes', 'required', 'string', 'max:500'], 'meter_type' => ['sometimes', Rule::in(['odometer'])],
            'value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999'], 'recorded_at' => ['sometimes', 'date'],
            'corrects_reading_id' => ['sometimes', 'nullable', 'ulid'], 'correction_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'vehicle_id' => ['sometimes', 'nullable', 'ulid'], 'driver_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'], 'purpose' => ['sometimes', 'required', 'string', 'max:120'],
            'odometer_km' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'ended_at' => ['sometimes', 'date'], 'usage_id' => ['sometimes', 'nullable', 'ulid'],
            'idempotency_key' => ['sometimes', 'required', 'uuid'], 'occurred_at' => ['sometimes', 'date'],
            'liters' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:9999999'], 'fuel_type' => ['sometimes', 'required', 'string', 'max:40'],
            'amount' => ['sometimes', 'required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/D'], 'supplier' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => ['sometimes', Rule::in(['preventive', 'corrective'])], 'task' => ['sometimes', 'required', 'string', 'max:160'],
            'interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:36500'], 'interval_km' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'last_completed_at' => ['sometimes', 'nullable', 'date'],
            'last_odometer_km' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'next_due_at' => ['sometimes', 'nullable', 'date'], 'next_due_odometer_km' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'next_due_odometer_epoch' => ['prohibited'],
            'active' => ['sometimes', 'boolean'],
            'immobilizes_vehicle' => ['sometimes', 'boolean'], 'workshop' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'required', 'string', 'max:500'], 'planned_at' => ['sometimes', 'nullable', 'date'],
            'cost_amount' => ['sometimes', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/D'], 'items' => ['sometimes', 'array', 'max:30'],
            'items.*' => ['array:plan_id,task,notes'], 'items.*.plan_id' => ['sometimes', 'nullable', 'ulid'],
            'items.*.task' => ['sometimes', 'string', 'max:160'], 'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'completed_item_ids' => ['sometimes', 'array', 'max:30'], 'completed_item_ids.*' => ['ulid'],
            'title' => ['sometimes', 'required', 'string', 'max:160'], 'document_type' => ['sometimes', 'required', 'string', 'max:60'],
            'expires_at' => ['sometimes', 'nullable', 'date'], 'file' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'receipt' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
        if ($this->routeIs('api.v1.vehicles.store', 'api.v1.vehicles.update')) {
            $rules['plate'] = ['required', 'string', 'max:24'];
            $rules['make'] = ['required', 'string', 'max:80'];
            $rules['model'] = ['required', 'string', 'max:100'];
            $rules['status'] = ['prohibited'];
        }
        if ($this->routeIs('api.v1.vehicles.status')) {
            $rules['status'] = ['required', Rule::in(['active', 'out_of_service', 'retired'])];
            $rules['reason'] = ['required', 'string', 'max:500'];
        }
        if ($this->routeIs('api.v1.vehicles.readings.store')) {
            $rules['idempotency_key'] = ['required', 'uuid'];
            $rules['value'] = ['required', 'numeric', 'min:0', 'max:999999999'];
            $rules['recorded_at'] = ['sometimes', 'date'];
            $rules['event_type'] = ['sometimes', Rule::in(['reading', 'reset'])];
            $rules['event_reason'] = ['sometimes', 'required_if:event_type,reset', 'nullable', 'string', 'max:500'];
            $rules['corrects_reading_id'] = ['prohibited'];
        }
        if ($this->routeIs('api.v1.vehicles.readings.store') && $this->input('event_type') === 'correction') {
            $rules['event_type'] = ['required', Rule::in(['correction'])];
            $rules['corrects_reading_id'] = ['required', 'ulid'];
            $rules['correction_reason'] = ['required', 'string', 'max:500'];
        }
        if ($this->routeIs('api.v1.vehicles.usages.store')) {
            $rules['purpose'] = ['required', 'string', 'max:120'];
            $rules['idempotency_key'] = ['required', 'uuid'];
            if (! ($this->user()?->can('can_manage_vehicles') ?? false)) {
                $rules['driver_id'] = ['prohibited'];
            }
        }
        if ($this->routeIs('api.v1.vehicles.usages.close')) {
            $rules['odometer_km'] = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'];
            $rules['notes'] = ['sometimes', 'nullable', 'string', 'max:5000'];
        }
        if ($this->routeIs('api.v1.vehicles.fuel.store')) {
            $rules['idempotency_key'] = ['required', 'uuid'];
            $rules['liters'] = ['required', 'numeric', 'gt:0', 'max:9999999'];
            $rules['fuel_type'] = ['required', 'string', 'max:40'];
            $rules['amount'] = ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/D'];
            if (($this->user()?->can('can_record_delivery_fuel') ?? false) && ! ($this->user()?->can('can_manage_vehicle_fuel') ?? false) && ! ($this->user()?->can('can_manage_vehicles') ?? false)) {
                $rules['usage_id'] = ['required', 'ulid'];
            }
        }
        if ($this->routeIs('api.v1.vehicles.plans.store', 'api.v1.vehicles.plans.update')) {
            $rules['task'] = ['required', 'string', 'max:160'];
        }
        if ($this->routeIs('api.v1.vehicles.maintenances.store')) {
            $rules['type'] = ['required', Rule::in(['preventive', 'corrective'])];
            $rules['description'] = ['required', 'string', 'max:500'];
            $rules['idempotency_key'] = ['required', 'uuid'];
        }
        if ($this->routeIs('api.v1.vehicles.documents.store', 'api.v1.vehicles.maintenances.documents.store')) {
            $rules['file'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
            $rules['title'] = ['required', 'string', 'max:160'];
            $rules['document_type'] = ['required', 'string', 'max:60'];
        }
        if ($this->routeIs('api.v1.vehicles.maintenances.complete')) {
            $rules['completed_item_ids'] = ['sometimes', 'array', 'max:30'];
            $rules['odometer_km'] = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'];
            $rules['cost_amount'] = ['sometimes', 'required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/D'];
            $rules['version'] = ['sometimes', 'integer', 'min:1'];
        }
        if ($this->routeIs('api.v1.vehicles.maintenances.cancel')) {
            $rules['reason'] = ['required', 'string', 'max:500'];
        }

        return $rules;
    }
}
