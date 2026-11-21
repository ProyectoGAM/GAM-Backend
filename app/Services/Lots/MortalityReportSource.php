<?php

namespace App\Services\Lots;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MortalityReportSource extends SqlReportSource
{
    public function __construct(private HistoricalFlockHousing $housing) {}

    public function definition(): ReportSourceDefinition
    {
        return $this->describe('lots.mortality', 'Mortandad', 'Bajas registradas por lote, fecha y galpón histórico; excluye anulaciones.', 'can_view_mortality', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'],
            'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'], 'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
            'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'], 'poultry_house' => ['label' => 'Galpón', 'type' => 'string'],
            'quantity' => ['label' => 'Aves fallecidas', 'type' => 'quantity', 'unit' => 'aves'],
            'notes' => ['label' => 'Observaciones', 'type' => 'string'],
        ], ['mortality_count' => ['label' => 'Total de bajas', 'type' => 'quantity', 'unit' => 'aves']],
            extraFilters: ['quantity' => ['label' => 'Aves fallecidas', 'type' => 'number', 'operators' => ['eq', 'gte', 'lte']]],
            standardQueries: [['label' => 'Bajas por mes y lote', 'configuration' => ['groupings' => ['month', 'flock', 'poultry_house'], 'metrics' => ['mortality_count']]]]);
    }

    protected function baseQuery(): Builder
    {
        $records = DB::table('mortality_records')->where('status', 'recorded')->selectRaw('*, occurred_at as date');

        return $this->housing->join(DB::query()->fromSub($records, 'r')->join('flocks as f', 'f.id', '=', 'r.flock_id'))
            ->whereRaw('(r.production_unit_id IS NULL OR r.production_unit_id = u.id)')
            ->selectRaw('r.id as row_key, r.date, f.public_id as flock_id, f.code as flock, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, r.quantity, r.notes');
    }

    protected function metricExpressions(): array
    {
        return ['mortality_count' => 'SUM(quantity)'];
    }
}
