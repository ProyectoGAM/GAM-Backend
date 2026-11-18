<?php

namespace App\Services\Vehicles;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VehicleMaintenanceReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('vehicles.maintenance', 'Mantenimiento de vehículos', 'Historial de intervenciones. El gasto total suma sólo las completadas y separa monedas.', 'can_view_vehicle_maintenance', [
            'date' => ['label' => 'Fecha de intervención', 'type' => 'datetime'], 'vehicle_id' => ['label' => 'Vehículo', 'type' => 'string'],
            'vehicle' => ['label' => 'Matrícula', 'type' => 'string'], 'type' => ['label' => 'Tipo', 'type' => 'string'],
            'status' => ['label' => 'Estado', 'type' => 'string'], 'description' => ['label' => 'Descripción', 'type' => 'string'],
            'workshop' => ['label' => 'Taller', 'type' => 'string'], 'completed_at' => ['label' => 'Completado', 'type' => 'datetime'],
            'cost_amount' => ['label' => 'Costo', 'type' => 'money', 'unit' => 'cost_currency'], 'cost_currency' => ['label' => 'Moneda', 'type' => 'string'],
            'immobilizes_vehicle' => ['label' => 'Inmoviliza', 'type' => 'string'],
        ], ['maintenance_count' => ['label' => 'Intervenciones', 'type' => 'quantity', 'unit' => 'intervenciones'], 'cost_total' => ['label' => 'Gasto completado', 'type' => 'money', 'unit' => 'cost_currency']],
            extraFilters: ['status' => ['label' => 'Estado', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['planned', 'in_progress', 'completed', 'cancelled']]],
            standardQueries: [['label' => 'Gastos completados por mes', 'configuration' => ['filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'completed']], 'groupings' => ['month', 'vehicle'], 'metrics' => ['cost_total']]]],
            additionalPermissions: ['can_view_vehicle_maintenance'], module: ['key' => 'expenses', 'label' => 'Gastos']);
    }

    protected function dimensionKeys(): array
    {
        return ['vehicle' => 'vehicle_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('vehicle_maintenances as m')->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->selectRaw("m.public_id as row_key, COALESCE(m.completed_at, m.started_at, m.planned_at, m.created_at) as date, v.public_id as vehicle_id, v.plate as vehicle, m.type, m.status, m.description, m.workshop, m.completed_at, m.cost_amount, m.cost_currency, CASE WHEN m.immobilizes_vehicle THEN 'Sí' ELSE 'No' END as immobilizes_vehicle");
    }

    protected function metricExpressions(): array
    {
        return ['maintenance_count' => 'COUNT(*)', 'cost_total' => "SUM(CASE WHEN status = 'completed' THEN cost_amount ELSE 0 END)"];
    }
}
