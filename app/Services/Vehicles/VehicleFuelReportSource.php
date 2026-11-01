<?php

namespace App\Services\Vehicles;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VehicleFuelReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('vehicles.fuel', 'Combustible y gastos', 'Cargas de combustible por vehículo y período, con litros e importe exacto en UYU.', 'vehicles.fuel.view', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'vehicle_id' => ['label' => 'Vehículo', 'type' => 'string'],
            'vehicle' => ['label' => 'Matrícula', 'type' => 'string'], 'driver_id' => ['label' => 'Conductor', 'type' => 'string'],
            'driver' => ['label' => 'Conductor', 'type' => 'string'], 'fuel_type' => ['label' => 'Combustible', 'type' => 'string'],
            'supplier' => ['label' => 'Estación / proveedor', 'type' => 'string'], 'liters' => ['label' => 'Litros', 'type' => 'quantity', 'unit' => 'l'],
            'amount' => ['label' => 'Importe', 'type' => 'money', 'unit' => 'UYU'], 'currency' => ['label' => 'Moneda', 'type' => 'string'],
            'odometer_km' => ['label' => 'Kilometraje', 'type' => 'number'],
        ], ['liters_total' => ['label' => 'Litros', 'type' => 'quantity', 'unit' => 'l'], 'amount_total' => ['label' => 'Gasto', 'type' => 'money', 'unit' => 'UYU']], additionalPermissions: ['vehicles.fuel.view']);
    }

    protected function dimensionKeys(): array
    {
        return ['vehicle' => 'vehicle_id', 'driver' => 'driver_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('vehicle_fuel_entries as f')->join('vehicles as v', 'v.id', '=', 'f.vehicle_id')->leftJoin('vehicle_usages as u', 'u.id', '=', 'f.usage_id')
            ->selectRaw("f.public_id as row_key, f.occurred_at as date, v.public_id as vehicle_id, v.plate as vehicle, CAST(u.driver_id AS TEXT) as driver_id, COALESCE(u.driver_snapshot, 'Sin responsable') as driver, f.fuel_type, f.supplier, f.liters, f.amount, f.currency, f.odometer_km");
    }

    protected function metricExpressions(): array
    {
        return ['liters_total' => 'SUM(liters)', 'amount_total' => 'SUM(amount)'];
    }
}
