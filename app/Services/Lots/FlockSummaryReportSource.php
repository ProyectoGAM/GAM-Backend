<?php

namespace App\Services\Lots;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FlockSummaryReportSource extends SqlReportSource
{
    public function __construct(private ProductionUnitAccess $access) {}

    public function definition(): ReportSourceDefinition
    {
        $columns = [
            'date' => ['label' => 'Fecha de ingreso', 'type' => 'datetime'],
            'entry_date' => ['label' => 'Día de ingreso', 'type' => 'date'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva actual', 'type' => 'string'],
            'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón alojado actualmente', 'type' => 'string'],
            'flock_id' => ['label' => 'Lote', 'type' => 'string'],
            'flock' => ['label' => 'Lote', 'type' => 'string'],
            'breed_id' => ['label' => 'Raza', 'type' => 'integer'],
            'breed' => ['label' => 'Raza', 'type' => 'string'],
            'capacity' => ['label' => 'Capacidad física del galpón', 'type' => 'quantity', 'unit' => 'aves'],
            'current_quantity' => ['label' => 'Aves actuales del lote', 'type' => 'quantity', 'unit' => 'aves'],
            'mortality' => ['label' => 'Mortandad del período', 'type' => 'quantity', 'unit' => 'aves'],
            'egg_production' => ['label' => 'Postura del período', 'type' => 'quantity', 'unit' => 'huevos'],
            'status' => ['label' => 'Estado actual', 'type' => 'string'],
        ];
        $filters = ['status' => ['label' => 'Estado actual', 'type' => 'enum', 'operators' => ['eq', 'in'],
            'options' => ['Activo', 'En cuarentena', 'Finalizado']]];
        foreach (['entry_date', 'capacity', 'current_quantity', 'mortality', 'egg_production'] as $field) {
            $filters[$field] = ['label' => $columns[$field]['label'], 'type' => $field === 'entry_date' ? 'date' : 'number',
                'operators' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte']];
        }

        return $this->describe('lots.summary', 'Lotes completos', 'Una fila por lote presente en el período. Alojamiento y estado actuales, capacidad física, mortandad y postura del período.',
            'can_view_flocks', $columns, [
                'flock_count' => ['label' => 'Lotes', 'type' => 'count'],
                'bird_count' => ['label' => 'Aves actuales', 'type' => 'quantity', 'unit' => 'aves'],
                'mortality_count' => ['label' => 'Mortandad del período', 'type' => 'quantity', 'unit' => 'aves'],
                'egg_count' => ['label' => 'Postura del período', 'type' => 'quantity', 'unit' => 'huevos'],
            ], $filters, [
                ['label' => 'Detalle por lote', 'configuration' => ['columns' => array_values(array_diff(array_keys($columns), ['date', 'flock_id', 'poultry_house_id', 'production_unit_id', 'breed_id']))]],
                ['label' => 'Resumen por galpón actual', 'configuration' => ['groupings' => ['production_unit', 'poultry_house'],
                    'metrics' => ['flock_count', 'bird_count', 'mortality_count', 'egg_count']]],
            ], ['can_view_mortality', 'can_view_egg_collections']);
    }

    protected function baseQuery(): Builder
    {
        return $this->records(null);
    }

    protected function prepareRecords(ReportQueryData $query): Builder
    {
        return $this->records($query);
    }

    private function records(?ReportQueryData $query): Builder
    {
        $mortality = $this->periodTotals('mortality_records', $query);
        $eggs = $this->periodTotals('egg_collections', $query);

        return DB::table('flocks as f')
            ->join('poultry_houses as h', 'h.id', '=', 'f.poultry_house_id')
            ->join('production_units as u', 'u.id', '=', 'f.production_unit_id')
            ->join('breeds as b', 'b.id', '=', 'f.breed_id')
            ->leftJoinSub($mortality, 'mortality', 'mortality.flock_id', '=', 'f.id')
            ->leftJoinSub($eggs, 'eggs', 'eggs.flock_id', '=', 'f.id')
            ->selectRaw('f.id as row_key, f.entry_date::timestamp AT TIME ZONE ? as date,
                f.entry_date, f.established_at, f.finalized_at, f.production_unit_id, u.name as production_unit,
                f.poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock,
                f.breed_id, b.name as breed, h.bird_capacity as capacity, f.current_quantity,
                COALESCE(mortality.quantity, 0) as mortality, COALESCE(eggs.quantity, 0) as egg_production,
                CASE f.status WHEN \'active\' THEN \'Activo\' WHEN \'quarantined\' THEN \'En cuarentena\'
                    WHEN \'finished\' THEN \'Finalizado\' END as status', [config('lots.timezone', 'America/Montevideo')]);
    }

    private function periodTotals(string $table, ?ReportQueryData $query): Builder
    {
        $rows = DB::table($table)->where('status', 'recorded');
        $unitId = $this->access->unitId();
        if ($unitId !== null) {
            $rows->where('production_unit_id', $unitId);
        }
        $rows = DB::query()->fromSub($rows->select(['flock_id', 'quantity', 'occurred_at as date']), 'events');
        if ($query !== null) {
            parent::filterPeriod($rows, $query);
        }

        return $rows->select('flock_id')->selectRaw('SUM(quantity) as quantity')->groupBy('flock_id');
    }

    protected function filterPeriod(Builder $builder, ReportQueryData $query): void
    {
        $timezone = config('lots.timezone', 'America/Montevideo');
        if ($query->from !== null) {
            $builder->where(fn (Builder $lifespan) => $lifespan->whereNull('finalized_at')
                ->orWhere('finalized_at', '>=', CarbonImmutable::parse($query->from, $timezone)->startOfDay()->toIso8601String()));
        }
        if ($query->to !== null) {
            $builder->where('entry_date', '<=', $query->to)
                ->where('established_at', '<', CarbonImmutable::parse($query->to, $timezone)->addDay()->startOfDay()->toIso8601String());
        }
    }

    protected function metricExpressions(): array
    {
        return ['flock_count' => 'COUNT(*)', 'bird_count' => 'SUM(current_quantity)',
            'mortality_count' => 'SUM(mortality)', 'egg_count' => 'SUM(egg_production)'];
    }

    protected function query(ReportQueryData $query): Builder
    {
        $result = parent::query($query);

        return $query->groupings === [] && $query->metrics !== []
            ? DB::query()->fromSub($result, 'flock_totals') : $result;
    }
}
