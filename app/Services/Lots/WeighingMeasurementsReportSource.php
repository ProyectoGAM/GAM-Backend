<?php

namespace App\Services\Lots;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class WeighingMeasurementsReportSource extends SqlReportSource
{
    public function __construct(private HistoricalFlockHousing $housing) {}

    public function definition(): ReportSourceDefinition
    {
        return $this->describe('lots.weighing-measurements', 'Pesadas', 'Muestras individuales y grupales con promedio ponderado por aves. Incluye registros diarios y pesajes históricos.', 'weighings.view', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'], 'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'], 'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'origin' => ['label' => 'Origen', 'type' => 'string'], 'mode' => ['label' => 'Modo', 'type' => 'string'],
            'bird_count' => ['label' => 'Aves representadas', 'type' => 'integer'],
            'total_weight_g' => ['label' => 'Peso total', 'type' => 'quantity', 'unit' => 'g'],
            'average_weight_g' => ['label' => 'Peso por ave', 'type' => 'quantity', 'unit' => 'g'],
        ], ['weighted_average_g' => ['label' => 'Promedio ponderado', 'type' => 'quantity', 'unit' => 'g'],
            'represented_birds' => ['label' => 'Aves representadas', 'type' => 'count']], [
                'mode' => ['label' => 'Modo', 'type' => 'enum', 'operators' => ['eq'], 'options' => ['individual', 'group']],
            ], [['label' => 'Resumen por jornada', 'configuration' => ['groupings' => ['day', 'poultry_house', 'flock'], 'metrics' => ['weighted_average_g', 'represented_birds']]]]);
    }

    protected function baseQuery(): Builder
    {
        $legacy = DB::table('weighing_measurements as samples')->join('weighings as w', 'w.id', '=', 'samples.weighing_id')
            ->selectRaw("'legacy-' || samples.id as row_key, w.flock_id, w.poultry_house_id, w.occurred_at as date, 'Histórico' as origin, w.mode, CASE WHEN w.mode = 'individual' THEN 1 ELSE samples.bird_count END as bird_count, CASE WHEN w.mode = 'individual' THEN samples.weight_g ELSE samples.total_weight_g END as total_weight_g, CASE WHEN w.mode = 'individual' THEN samples.weight_g ELSE samples.average_weight_g END as average_weight_g");
        $daily = DB::table('daily_weighing_entries as samples')->join('daily_weighings as w', 'w.id', '=', 'samples.daily_weighing_id')
            ->whereNull('samples.deleted_at')->selectRaw("'daily-' || samples.id as row_key, w.flock_id, NULL::bigint as poultry_house_id, samples.occurred_at as date, 'Diario' as origin, samples.mode, samples.bird_count, samples.total_weight_g, samples.average_weight_g");
        $events = $legacy->unionAll($daily);

        return $this->housing->join(DB::query()->fromSub($events, 'r')->join('flocks as f', 'f.id', '=', 'r.flock_id'))
            ->selectRaw('r.row_key, r.date, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock, r.origin, r.mode, r.bird_count, r.total_weight_g, r.average_weight_g');
    }

    protected function metricExpressions(): array
    {
        return ['weighted_average_g' => 'SUM(total_weight_g) / NULLIF(SUM(bird_count), 0)', 'represented_birds' => 'SUM(bird_count)'];
    }
}
