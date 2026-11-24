<?php

namespace App\Services\FarmStructure;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MaintenanceReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('expenses.poultry-house-maintenance', 'Mantenimiento de galpones', 'Intervenciones completadas y sus gastos registrados. Las cancelaciones se excluyen y cada moneda se agrega por separado.', 'can_view_poultry_houses', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'], 'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
            'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'], 'poultry_house' => ['label' => 'Galpón', 'type' => 'string'],
            'description' => ['label' => 'Descripción', 'type' => 'string'],
            'cost_amount' => ['label' => 'Costo', 'type' => 'money', 'unit' => 'cost_currency'],
            'cost_currency' => ['label' => 'Moneda', 'type' => 'string'],
            'responsible' => ['label' => 'Responsable registrado', 'type' => 'string'],
        ], ['cost_total' => ['label' => 'Gasto total', 'type' => 'money', 'unit' => 'cost_currency'], 'maintenance_count' => ['label' => 'Intervenciones', 'type' => 'count']],
            extraFilters: [
                'cost_currency' => ['label' => 'Moneda', 'type' => 'string', 'operators' => ['eq', 'in'], 'options_source' => 'cost_currency'],
                'description' => ['label' => 'Descripción', 'type' => 'string', 'operators' => ['contains']],
            ], standardQueries: [['label' => 'Gastos por mes y galpón', 'configuration' => ['groupings' => ['month', 'poultry_house'], 'metrics' => ['cost_total', 'maintenance_count']]]], supportsPublicLinks: false);
    }

    protected function baseQuery(): Builder
    {
        return DB::table('maintenances as m')->join('poultry_houses as h', 'h.id', '=', 'm.poultry_house_id')
            ->join('production_units as u', 'u.id', '=', 'h.production_unit_id')->where('m.status', 'completed')
            ->selectRaw('m.id as row_key, m.maintenance_date::timestamp AT TIME ZONE ? as date, h.production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house,
                m.description, m.cost_amount, m.cost_currency, m.responsible_name as responsible', [config('lots.timezone', 'America/Montevideo')]);
    }

    protected function metricExpressions(): array
    {
        return ['cost_total' => 'SUM(cost_amount)', 'maintenance_count' => 'COUNT(*)'];
    }
}
