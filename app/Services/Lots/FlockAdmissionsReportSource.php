<?php

namespace App\Services\Lots;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FlockAdmissionsReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('lots.admissions', 'Lotes', 'Ingresos y alojamientos históricos. La cantidad corresponde al ingreso, no a las aves vivas actuales.', 'flocks.view', [
            'date' => ['label' => 'Fecha de ingreso', 'type' => 'datetime'], 'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'], 'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'], 'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'breed' => ['label' => 'Raza', 'type' => 'string'], 'quantity' => ['label' => 'Aves ingresadas', 'type' => 'quantity', 'unit' => 'aves'],
        ], ['bird_count' => ['label' => 'Aves ingresadas', 'type' => 'quantity', 'unit' => 'aves']]);
    }

    protected function baseQuery(): Builder
    {
        return DB::table('flock_movements as m')->join('flocks as f', 'f.id', '=', 'm.destination_flock_id')
            ->join('breeds as b', 'b.id', '=', 'f.breed_id')->join('poultry_houses as h', 'h.id', '=', 'm.destination_poultry_house_id')
            ->join('production_units as u', 'u.id', '=', 'h.production_unit_id')
            ->whereIn('m.type', ['admission', 'partial_new', 'partial_existing', 'total', 'total_existing'])
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('flock_movements as reversal')->whereColumn('reversal.reverses_movement_id', 'm.id'))
            ->selectRaw('m.id as row_key, m.occurred_at as date, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock, b.name as breed, m.quantity');
    }

    protected function metricExpressions(): array
    {
        return ['bird_count' => 'SUM(quantity)'];
    }
}
