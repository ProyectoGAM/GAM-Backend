<?php

namespace App\Services\Lots;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EggProductionReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('lots.egg-production', 'Postura', 'Recolecciones válidas y producción histórica por galpón. Incluye lotes finalizados; el período comprende el historial registrado.', 'egg-collections.view', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'], 'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'], 'flock_id' => ['label' => 'Lote', 'type' => 'string'],
            'flock' => ['label' => 'Lote', 'type' => 'string'], 'quantity' => ['label' => 'Huevos', 'type' => 'quantity', 'unit' => 'huevos'],
        ], ['egg_count' => ['label' => 'Huevos producidos', 'type' => 'quantity', 'unit' => 'huevos']], standardQueries: [
            ['label' => 'Huevos de toda la vida del galpón', 'configuration' => ['groupings' => ['production_unit', 'poultry_house'], 'metrics' => ['egg_count']]],
        ]);
    }

    protected function baseQuery(): Builder
    {
        return DB::table('egg_collections as e')->join('flocks as f', 'f.id', '=', 'e.flock_id')
            ->join('poultry_houses as h', 'h.id', '=', 'e.poultry_house_id')->join('production_units as u', 'u.id', '=', 'e.production_unit_id')
            ->where('e.status', 'recorded')->selectRaw('e.id as row_key, e.occurred_at as date, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock, e.quantity');
    }

    protected function metricExpressions(): array
    {
        return ['egg_count' => 'SUM(quantity)'];
    }
}
