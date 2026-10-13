<?php

namespace App\Services\ManagementPlans;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FeedSupplyReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('management.feed-supply', 'Alimentación', 'Ración registrada y pauta estimada separadas. En días sin carga se repite la última cantidad diaria, sin extenderla antes del primer dato, después del cierre o fuera del alojamiento del lote.', 'management-plans.view', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'], 'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'], 'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'product_id' => ['label' => 'Alimento', 'type' => 'integer'], 'product' => ['label' => 'Alimento', 'type' => 'string'],
            'quantity' => ['label' => 'Cantidad suministrada', 'type' => 'quantity', 'unit' => 'base_unit'],
            'base_unit' => ['label' => 'Unidad', 'type' => 'string'], 'notes' => ['label' => 'Comentarios', 'type' => 'string'],
            'kind' => ['label' => 'Origen de la cantidad', 'type' => 'string'],
            'period_end' => ['label' => 'Hasta', 'type' => 'date'], 'covered_days' => ['label' => 'Días', 'type' => 'integer'],
        ], ['feed_quantity' => ['label' => 'Ración registrada', 'type' => 'quantity', 'unit' => 'base_unit'],
            'estimated_feed_quantity' => ['label' => 'Ración estimada', 'type' => 'quantity', 'unit' => 'base_unit']],
            extraFilters: ['kind' => ['label' => 'Origen', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Registrado', 'Estimado']]], standardQueries: [
                ['label' => 'Ración de toda la vida del galpón', 'configuration' => ['groupings' => ['production_unit', 'poultry_house', 'product', 'base_unit'], 'metrics' => ['feed_quantity', 'estimated_feed_quantity']]],
            ]);
    }

    protected function dimensionKeys(): array
    {
        return [...parent::dimensionKeys(), 'product' => 'product_id'];
    }

    protected function baseQuery(): Builder
    {
        return $this->records(null, null);
    }

    protected function prepareRecords(ReportQueryData $query): Builder
    {
        return $this->records($query->from, $query->to);
    }

    /** La pauta diaria se corta ante otra carga, traslado, cierre o fecha final consultada. */
    private function records(?string $from, ?string $to): Builder
    {
        $timezone = config('lots.timezone', 'America/Montevideo');
        $sql = <<<'SQL'
WITH daily AS (
    SELECT s.flock_id, s.poultry_house_id, s.production_unit_id, s.product_id,
        MIN(s.id) as id, MAX(s.product_name_snapshot) as product,
        (s.occurred_at AT TIME ZONE ?)::date as local_date,
        SUM(CASE WHEN s.unit = 'g' THEN s.quantity / 1000 ELSE s.quantity END) as daily_quantity
    FROM feed_supplies s WHERE s.status = 'recorded'
    GROUP BY s.flock_id, s.poultry_house_id, s.production_unit_id, s.product_id, local_date
), observations AS (
    SELECT flock_id, local_date, LEAD(local_date) OVER (PARTITION BY flock_id ORDER BY local_date) as next_date
    FROM (SELECT DISTINCT flock_id, local_date FROM daily) dates
), bounds AS (
    SELECT d.*, GREATEST(d.local_date + 1, COALESCE(?::date, d.local_date + 1)) as start_date,
        LEAST(COALESCE(o.next_date - 1, ?::date),
              COALESCE((f.finalized_at AT TIME ZONE ?)::date - 1, ?::date),
              COALESCE(?::date, ?::date),
              COALESCE((SELECT (m.occurred_at AT TIME ZONE ?)::date - 1 FROM flock_movements m
                 WHERE (m.source_flock_id = f.id OR m.destination_flock_id = f.id)
                 AND (m.occurred_at AT TIME ZONE ?)::date >= d.local_date
                 AND jsonb_exists(m.after, f.public_id)
                 AND (m.after -> f.public_id ->> 'poultry_house_id')::bigint <> d.poultry_house_id
                 ORDER BY m.occurred_at, m.id LIMIT 1), ?::date)) as end_date
    FROM daily d JOIN observations o ON o.flock_id = d.flock_id AND o.local_date = d.local_date
    JOIN flocks f ON f.id = d.flock_id
), facts AS (
    SELECT 'record-' || s.id as row_key, s.occurred_at as date, s.flock_id, s.poultry_house_id, s.production_unit_id,
        s.product_id, s.product_name_snapshot as product, CASE WHEN s.unit = 'g' THEN s.quantity / 1000 ELSE s.quantity END as quantity,
        'Registrado' as kind, (s.occurred_at AT TIME ZONE ?)::date as period_end, 1 as covered_days, s.notes
    FROM feed_supplies s WHERE s.status = 'recorded'
    UNION ALL
    SELECT 'estimate-' || b.id || '-' || estimated.day::date, estimated.day AT TIME ZONE ?, b.flock_id, b.poultry_house_id, b.production_unit_id,
        b.product_id, b.product, b.daily_quantity, 'Estimado', estimated.day::date,
        1, 'Pauta: última cantidad diaria registrada' FROM bounds b
        CROSS JOIN LATERAL generate_series(b.start_date::timestamp, b.end_date::timestamp, interval '1 day') as estimated(day)
        WHERE b.end_date >= b.start_date
)
SELECT facts.row_key, facts.date, u.id as production_unit_id, u.name as production_unit,
       h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock,
       facts.product_id, facts.product, facts.quantity, 'kg' as base_unit, facts.notes, facts.kind, facts.period_end, facts.covered_days
FROM facts JOIN flocks f ON f.id = facts.flock_id JOIN poultry_houses h ON h.id = facts.poultry_house_id JOIN production_units u ON u.id = facts.production_unit_id
SQL;
        $today = now($timezone)->toDateString();
        $bindings = [$timezone, $from, $today, $timezone, $today, $to, $today, $timezone, $timezone, $today, $timezone, $timezone];

        return DB::query()->fromRaw('('.$sql.') as feeding', $bindings)->select('feeding.*');
    }

    protected function metricExpressions(): array
    {
        return ['feed_quantity' => "SUM(CASE WHEN kind = 'Registrado' THEN quantity ELSE 0 END)",
            'estimated_feed_quantity' => "SUM(CASE WHEN kind = 'Estimado' THEN quantity ELSE 0 END)"];
    }
}
