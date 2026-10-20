<?php

namespace App\Services\ManagementPlans;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\Lots\HistoricalFlockHousing;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ManagementExecutionsReportSource extends SqlReportSource
{
    public function __construct(private HistoricalFlockHousing $housing) {}

    public function definition(): ReportSourceDefinition
    {
        return $this->describe('management.executions', 'Manejo general', 'Ejecuciones reales y fecha o período de su manual. Las tareas condicionales o sin calendario se identifican sin inventar fechas.', 'management-plans.view', [
            'date' => ['label' => 'Fecha realizada', 'type' => 'datetime'], 'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'], 'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'], 'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'practice' => ['label' => 'Manejo realizado', 'type' => 'string'], 'scheduled_from' => ['label' => 'Fecha inicial según manual', 'type' => 'date'],
            'scheduled_to' => ['label' => 'Fecha final según manual', 'type' => 'date'],
            'schedule' => ['label' => 'Programación', 'type' => 'string'], 'notes' => ['label' => 'Comentarios', 'type' => 'string'],
        ], ['execution_count' => ['label' => 'Manejos realizados', 'type' => 'count']]);
    }

    protected function baseQuery(): Builder
    {
        $streams = [];
        foreach (['manual_practices' => 'manual_practice', 'vaccination_applications' => 'vaccination', 'medicine_applications' => 'medication', 'ration_changes' => 'ration_change'] as $table => $type) {
            $title = match ($type) {
                'manual_practice' => 'practice_title_snapshot',
                'vaccination' => "product_snapshot->>'name'",
                'medication' => 'medicine_name_snapshot',
                default => 'ration_description',
            };
            $streams[] = DB::table($table.' as e')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('management_execution_corrections as c')->where('c.original_type', $type)->whereColumn('c.original_public_id', 'e.public_id'))
                ->selectRaw("'{$type}-' || e.id as row_key, e.flock_id, e.poultry_house_id, e.occurred_at as date, e.plan_activity_id, e.{$title} as practice, e.notes");
        }
        $streams[] = DB::table('feed_supplies as e')->where('e.status', 'recorded')
            ->selectRaw("'feed_supply-' || e.id as row_key, e.flock_id, e.poultry_house_id, e.occurred_at as date, NULL::varchar as plan_activity_id, 'Alimentación: ' || e.product_name_snapshot as practice, e.notes");
        $records = array_shift($streams);
        foreach ($streams as $stream) {
            $records->unionAll($stream);
        }

        return $this->housing->join(DB::query()->fromSub($records, 'r')->join('flocks as f', 'f.id', '=', 'r.flock_id'))
            ->leftJoin('flock_plan_activities as a', 'a.public_id', '=', 'r.plan_activity_id')
            ->selectRaw('r.row_key, r.date, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock, r.practice, r.notes')
            ->selectRaw("CASE WHEN a.conditional OR a.timing_kind = 'unscheduled' THEN NULL ELSE f.entry_date + COALESCE(a.start_day - 1, (a.start_week - 1) * 7) END as scheduled_from")
            ->selectRaw("CASE WHEN a.conditional OR a.timing_kind = 'unscheduled' THEN NULL ELSE f.entry_date + COALESCE(a.end_day - 1, a.end_week * 7 - 1, a.start_day - 1, a.start_week * 7 - 1) END as scheduled_to")
            ->selectRaw("CASE WHEN a.id IS NULL THEN 'Sin vínculo al manual' WHEN a.conditional THEN 'Condicional' WHEN a.interval_days IS NOT NULL THEN 'Recurrente cada ' || a.interval_days || ' días' WHEN a.timing_kind = 'unscheduled' THEN 'Sin fecha fija' ELSE 'Según manual' END as schedule");
    }

    protected function metricExpressions(): array
    {
        return ['execution_count' => 'COUNT(*)'];
    }
}
