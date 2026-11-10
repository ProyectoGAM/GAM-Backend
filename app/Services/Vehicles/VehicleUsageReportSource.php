<?php

namespace App\Services\Vehicles;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VehicleUsageReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('vehicles.usage', 'Uso de vehículos', 'Usos registrados con responsable, reparto y kilometraje de tablero disponible.', 'can_view_vehicles', [
            'date' => ['label' => 'Inicio', 'type' => 'datetime'], 'vehicle_id' => ['label' => 'Vehículo', 'type' => 'string'],
            'vehicle' => ['label' => 'Matrícula', 'type' => 'string'], 'driver_id' => ['label' => 'Conductor', 'type' => 'string'],
            'driver' => ['label' => 'Conductor', 'type' => 'string'], 'purpose' => ['label' => 'Tipo de uso', 'type' => 'string'],
            'delivery_id' => ['label' => 'Reparto', 'type' => 'string'], 'started_at' => ['label' => 'Inicio', 'type' => 'datetime'],
            'ended_at' => ['label' => 'Fin', 'type' => 'datetime'], 'start_odometer_km' => ['label' => 'Km de salida', 'type' => 'number'],
            'end_odometer_km' => ['label' => 'Km de cierre', 'type' => 'number'], 'distance_km' => ['label' => 'Distancia registrada', 'type' => 'quantity', 'unit' => 'km'],
        ], ['use_count' => ['label' => 'Usos', 'type' => 'quantity', 'unit' => 'usos'], 'distance_total_km' => ['label' => 'Distancia registrada', 'type' => 'quantity', 'unit' => 'km']], additionalPermissions: ['can_view_vehicles']);
    }

    protected function dimensionKeys(): array
    {
        return ['vehicle' => 'vehicle_id', 'driver' => 'driver_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('vehicle_usages as u')->join('vehicles as v', 'v.id', '=', 'u.vehicle_id')->leftJoin('deliveries as d', 'd.id', '=', 'u.delivery_id')
            ->selectRaw("u.public_id as row_key, u.started_at as date, v.public_id as vehicle_id, v.plate as vehicle, COALESCE(u.driver_snapshot, 'Sin responsable') as driver, CAST(u.driver_id AS TEXT) as driver_id, u.purpose, d.public_id as delivery_id, u.started_at, u.ended_at, u.start_odometer_km, u.end_odometer_km, CASE WHEN u.start_odometer_km IS NOT NULL AND u.end_odometer_km >= u.start_odometer_km THEN u.end_odometer_km - u.start_odometer_km END as distance_km");
    }

    protected function metricExpressions(): array
    {
        return ['use_count' => 'COUNT(*)', 'distance_total_km' => 'SUM(distance_km)'];
    }
}
