<?php

namespace App\Services\ManagementPlans;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Lots\FlockSummaryReportSource;
use App\Services\Lots\WeighingMeasurementsReportSource;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class ManagementOverviewReportSource extends SqlReportSource
{
    private const array SECTIONS = ['Lotes', 'Plan de manejo', 'Manejos realizados', 'Pesadas', 'Movimientos y fraccionamientos'];

    public function __construct(
        private FlockSummaryReportSource $flocks,
        private ManagementExecutionsReportSource $executions,
        private WeighingMeasurementsReportSource $weighings,
        private ProductionUnitAccess $access,
    ) {}

    public function definition(): ReportSourceDefinition
    {
        $fields = ['date', 'production_unit_id', 'production_unit', 'poultry_house_id', 'poultry_house', 'flock_id', 'flock',
            'entry_date', 'breed', 'capacity', 'current_quantity', 'mortality', 'egg_production', 'status',
            'practice', 'management_type', 'responsible', 'scheduled_from', 'scheduled_to', 'notes',
            'bird_count', 'total_weight_g', 'average_weight_g'];
        $available = $this->flocks->definition()->columns + $this->executions->definition()->columns + $this->weighings->definition()->columns;
        $columns = ['section' => ['label' => 'Sección', 'type' => 'string']] + array_intersect_key($available, array_flip($fields)) + [
            'plan_id' => ['label' => 'Plan del lote', 'type' => 'string'],
            'plan' => ['label' => 'Manual del plan vigente', 'type' => 'string'],
            'plan_revision' => ['label' => 'Revisión vigente del plan', 'type' => 'integer'],
            'movement_type' => ['label' => 'Movimiento', 'type' => 'string'],
            'source_flock' => ['label' => 'Lote de origen', 'type' => 'string'],
            'destination_flock' => ['label' => 'Lote de destino', 'type' => 'string'],
            'moved_quantity' => ['label' => 'Aves del movimiento', 'type' => 'quantity', 'unit' => 'aves'],
        ];
        $columns['date']['label'] = 'Fecha';
        $columns['poultry_house']['label'] = 'Galpón';
        $columns['production_unit']['label'] = 'Unidad productiva';

        return $this->describe('management.overview', 'Manejos', 'Vista conjunta por secciones: lotes presentes en el período, plan vigente, manejos realizados, pesadas y movimientos no revertidos. Cada hecho aparece separado para evitar multiplicar cantidades.',
            'can_view_management_plans', $columns, [
                'record_count' => ['label' => 'Registros', 'type' => 'count'],
                'flock_count' => ['label' => 'Lotes distintos', 'type' => 'count'],
            ], extraFilters: [
                'section' => ['label' => 'Sección', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => self::SECTIONS],
            ], additionalPermissions: ['can_view_flocks', 'can_view_mortality', 'can_view_egg_collections', 'can_view_weighings']);
    }

    protected function baseQuery(): Builder
    {
        return $this->records(null);
    }

    protected function dimensionKeys(): array
    {
        return [...parent::dimensionKeys(), 'plan' => 'plan_id'];
    }

    protected function prepareRecords(ReportQueryData $query): Builder
    {
        return $this->records($query);
    }

    private function records(?ReportQueryData $period): Builder
    {
        $streams = [
            $this->project($this->flocks->detailQuery($period), $this->flocks->definition()->columns, 'Lotes'),
            $this->project($this->plannedActivities($period), array_fill_keys(['row_key', 'date', 'production_unit_id', 'production_unit', 'poultry_house_id', 'poultry_house', 'flock_id', 'flock', 'practice', 'management_type', 'scheduled_from', 'scheduled_to', 'notes'], []), 'Plan de manejo'),
            $this->project($this->executions->detailQuery($period), $this->executions->definition()->columns, 'Manejos realizados'),
            $this->project($this->weighings->detailQuery($period), $this->weighings->definition()->columns, 'Pesadas'),
            $this->project($this->movements($period), array_fill_keys(['row_key', 'date', 'production_unit_id', 'production_unit', 'poultry_house_id', 'poultry_house', 'flock_id', 'flock', 'movement_type', 'source_flock', 'destination_flock', 'moved_quantity', 'notes'], []), 'Movimientos y fraccionamientos'),
        ];
        $records = array_shift($streams);
        foreach ($streams as $stream) {
            $records->unionAll($stream);
        }

        return DB::query()->fromSub($records, 'overview')->join('flocks as f', 'f.public_id', '=', 'overview.flock_id')
            ->leftJoin('flock_plans as plan', 'plan.flock_id', '=', 'f.id')
            ->leftJoin('plan_template_versions as version', 'version.id', '=', 'plan.plan_template_version_id')
            ->leftJoin('plan_templates as template', 'template.id', '=', 'version.plan_template_id')
            ->selectRaw('overview.*, plan.public_id as plan_id, template.name as plan, plan.current_revision as plan_revision');
    }

    /** Proyecta cada sección al mismo contrato sin unir relaciones uno-a-muchos entre sí. @param array<string, mixed> $available */
    private function project(Builder $records, array $available, string $section): Builder
    {
        $query = DB::query()->fromSub($records, 'r')->selectRaw('? || r.row_key::text as row_key', [$section]);
        foreach ($this->definition()->columns as $field => $column) {
            if (in_array($field, ['plan_id', 'plan', 'plan_revision'], true)) {
                continue;
            }
            if ($field === 'section') {
                $query->selectRaw('?::text as section', [$section]);

                continue;
            }
            $type = match ($column['type']) {
                'integer' => 'bigint', 'quantity' => 'numeric', 'date' => 'date', 'datetime' => 'timestamptz', default => 'text',
            };
            $value = array_key_exists($field, $available) ? 'r.'.$field : 'NULL';
            $query->selectRaw($value.'::'.$type.' as "'.$field.'"');
        }

        return $query;
    }

    /** Las secciones ya aplicaron sus propias fechas: ingreso/presencia, programación o fecha del hecho. */
    protected function filterPeriod(Builder $builder, ReportQueryData $query): void {}

    private function plannedActivities(?ReportQueryData $period): Builder
    {
        $dates = DB::table('flock_plan_activities as a')
            ->join('flock_plan_revisions as revision', 'revision.id', '=', 'a.flock_plan_revision_id')
            ->join('flock_plans as plan', fn (JoinClause $join) => $join->on('plan.id', '=', 'revision.flock_plan_id')->on('plan.current_revision', '=', 'revision.number'))
            ->join('flocks as f', 'f.id', '=', 'plan.flock_id')
            ->join('poultry_houses as h', 'h.id', '=', 'f.poultry_house_id')->join('production_units as u', 'u.id', '=', 'f.production_unit_id')
            ->selectRaw('a.id as row_key, f.production_unit_id, u.name as production_unit, f.poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock, f.established_at, f.finalized_at, a.interval_days, a.title as practice, a.notes')
            ->selectRaw("CASE a.type WHEN 'manual_practice' THEN 'Práctica manual' WHEN 'vaccination' THEN 'Vacunación' WHEN 'medication' THEN 'Medicación' WHEN 'ration_change' THEN 'Cambio de ración' WHEN 'weighing' THEN 'Pesada' WHEN 'flock_movement' THEN 'Movimiento de lote' WHEN 'egg_collection' THEN 'Postura' WHEN 'mortality' THEN 'Mortandad' END as management_type")
            ->selectRaw("CASE WHEN a.conditional OR a.timing_kind = 'unscheduled' THEN NULL ELSE plan.baseline_date + COALESCE(a.start_day - 1, (a.start_week - 1) * 7) END as scheduled_from")
            ->selectRaw("CASE WHEN a.conditional OR a.timing_kind = 'unscheduled' THEN NULL ELSE plan.baseline_date + COALESCE(a.end_day - 1, a.end_week * 7 - 1, a.start_day - 1, a.start_week * 7 - 1) END as scheduled_to");
        $rows = DB::query()->fromSub($dates, 'activities')->select('activities.*')->selectRaw('scheduled_from::timestamp AT TIME ZONE ? as date', [config('lots.timezone', 'America/Montevideo')]);
        if ($period?->from !== null) {
            $rows->where(fn (Builder $query) => $query->whereNull('finalized_at')->orWhere('finalized_at', '>=', CarbonImmutable::parse($period->from, config('lots.timezone'))->startOfDay()->toIso8601String()))
                ->where(fn (Builder $query) => $query->whereNull('scheduled_to')->orWhereNotNull('interval_days')->orWhere('scheduled_to', '>=', $period->from));
        }
        if ($period?->to !== null) {
            $rows->where('established_at', '<', CarbonImmutable::parse($period->to, config('lots.timezone'))->addDay()->startOfDay()->toIso8601String())
                ->where(fn (Builder $query) => $query->whereNull('scheduled_from')->orWhere('scheduled_from', '<=', $period->to));
        }

        return $rows;
    }

    private function movements(?ReportQueryData $period): Builder
    {
        $records = DB::table('flock_movements as m')
            ->join('flocks as f', fn (JoinClause $join) => $join->on('f.id', '=', 'm.source_flock_id')->orOn('f.id', '=', 'm.destination_flock_id'))
            ->leftJoin('flocks as origin', 'origin.id', '=', 'm.source_flock_id')->leftJoin('flocks as destination', 'destination.id', '=', 'm.destination_flock_id')
            ->leftJoin('poultry_houses as origin_house', 'origin_house.id', '=', 'm.source_poultry_house_id')
            ->leftJoin('poultry_houses as destination_house', 'destination_house.id', '=', 'm.destination_poultry_house_id')
            ->leftJoin('poultry_houses as h', fn (JoinClause $join) => $join->whereRaw('h.id = CASE WHEN f.id = m.source_flock_id THEN m.source_poultry_house_id ELSE m.destination_poultry_house_id END')->on('h.production_unit_id', '=', 'f.production_unit_id'))
            ->join('production_units as u', 'u.id', '=', 'f.production_unit_id')
            ->whereNull('m.reverses_movement_id')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('flock_movements as reversal')->whereColumn('reversal.reverses_movement_id', 'm.id'))
            ->selectRaw("m.id || '-' || f.id as row_key, m.occurred_at as date, f.production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock,
                CASE m.type WHEN 'admission' THEN 'Ingreso' WHEN 'partial_new' THEN 'Fraccionamiento a lote nuevo' WHEN 'partial_existing' THEN 'Fraccionamiento a lote existente'
                WHEN 'total' THEN 'Traslado total' WHEN 'total_existing' THEN 'Unificación' WHEN 'departure' THEN 'Egreso' WHEN 'mortality' THEN 'Mortandad' ELSE m.type END as movement_type,
                origin.code as source_flock, destination.code as destination_flock, m.quantity as moved_quantity, m.reason as notes");
        if (($unitId = $this->access->unitId()) !== null) {
            foreach (['origin', 'destination', 'origin_house', 'destination_house'] as $endpoint) {
                $records->where(fn (Builder $query) => $query->whereNull($endpoint.'.id')->orWhere($endpoint.'.production_unit_id', $unitId));
            }
        }
        $rows = DB::query()->fromSub($records, 'movements')->select('movements.*');
        if ($period !== null) {
            parent::filterPeriod($rows, $period);
        }

        return $rows;
    }

    protected function metricExpressions(): array
    {
        return ['record_count' => 'COUNT(*)', 'flock_count' => 'COUNT(DISTINCT flock_id)'];
    }
}
