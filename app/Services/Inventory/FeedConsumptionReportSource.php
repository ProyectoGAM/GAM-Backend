<?php

namespace App\Services\Inventory;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class FeedConsumptionReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('management.feeding', 'Alimentación', 'Consumos diarios por receta con su última rectificación. El galpón corresponde al alojamiento al cierre de la jornada; sin historia comprobable permanece vacío.', 'can_view_flock_feeding', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
            'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'],
            'flock_id' => ['label' => 'Lote', 'type' => 'string'],
            'flock' => ['label' => 'Lote', 'type' => 'string'],
            'feed_recipe_id' => ['label' => 'Alimento', 'type' => 'integer'],
            'feed_recipe' => ['label' => 'Alimento', 'type' => 'string'],
            'quantity' => ['label' => 'Cantidad de alimento', 'type' => 'quantity', 'unit' => 'base_unit'],
            'base_unit' => ['label' => 'Unidad', 'type' => 'string'],
            'mode' => ['label' => 'Modo de alimentación', 'type' => 'string'],
            'notes' => ['label' => 'Observaciones de rectificación', 'type' => 'string'],
        ], [
            'feed_quantity' => ['label' => 'Alimento consumido', 'type' => 'quantity', 'unit' => 'base_unit'],
            'consumption_count' => ['label' => 'Jornadas registradas', 'type' => 'count'],
        ], extraFilters: [
            'mode' => ['label' => 'Modo de alimentación', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Automático', 'Manual']],
            'quantity' => ['label' => 'Cantidad de alimento (kg)', 'type' => 'number', 'operators' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte']],
        ], standardQueries: [
            ['label' => 'Detalle de alimentación', 'configuration' => ['columns' => ['date', 'flock', 'poultry_house', 'feed_recipe', 'quantity', 'base_unit', 'mode']]],
            ['label' => 'Alimentación por día y lote', 'configuration' => ['groupings' => ['day', 'flock', 'poultry_house', 'feed_recipe'], 'metrics' => ['feed_quantity']]],
            ['label' => 'Alimentación por galpón y mes', 'configuration' => ['groupings' => ['month', 'poultry_house', 'feed_recipe'], 'metrics' => ['feed_quantity']]],
        ]);
    }

    protected function dimensionKeys(): array
    {
        return [...parent::dimensionKeys(), 'feed_recipe' => 'feed_recipe_id'];
    }

    protected function baseQuery(): Builder
    {
        $timezone = config('lots.timezone', 'America/Montevideo');
        $correction = DB::table('feed_consumption_corrections as correction')
            ->whereColumn('correction.feed_consumption_id', 'c.id')->orderByDesc('correction.id')->limit(1);

        // El consumo diario pertenece al galpón al cierre, incluso si se recuperó después de un traslado.
        $housing = DB::table('flock_movements as m')
            ->selectRaw("(m.after -> f.public_id ->> 'poultry_house_id')::bigint")
            ->where(fn (Builder $query) => $query->whereColumn('m.source_flock_id', 'f.id')->orWhereColumn('m.destination_flock_id', 'f.id'))
            ->whereRaw('jsonb_exists(m.after, f.public_id)')
            ->whereRaw('m.occurred_at < ((c.consumption_date + 1)::timestamp AT TIME ZONE ?)', [$timezone])
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')->limit(1);

        $records = DB::table('feed_consumptions as c')->join('flocks as f', 'f.id', '=', 'c.flock_id')
            ->leftJoinLateral($correction, 'latest')
            ->selectRaw('c.id as row_key, c.consumption_date::timestamp AT TIME ZONE ? as date, c.production_unit_id, f.public_id as flock_id, f.code as flock', [$timezone])
            ->selectSub($housing, 'poultry_house_id')
            ->selectRaw('COALESCE(latest.feed_recipe_id, c.feed_recipe_id) as feed_recipe_id, COALESCE(latest.quantity_grams, c.quantity_grams)::numeric / 1000 as quantity')
            ->selectRaw("CASE WHEN c.mode = 'manual' THEN 'Manual' ELSE 'Automático' END as mode, latest.reason as notes");

        return DB::query()->fromSub($records, 'r')
            ->join('production_units as u', 'u.id', '=', 'r.production_unit_id')
            ->join('feed_recipes as recipe', 'recipe.id', '=', 'r.feed_recipe_id')
            ->leftJoin('poultry_houses as h', fn (JoinClause $join) => $join->on('h.id', '=', 'r.poultry_house_id')->on('h.production_unit_id', '=', 'r.production_unit_id'))
            ->selectRaw("r.row_key, r.date, r.production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, r.flock_id, r.flock, r.feed_recipe_id, recipe.name as feed_recipe, r.quantity, 'kg' as base_unit, r.mode, r.notes");
    }

    protected function metricExpressions(): array
    {
        return ['feed_quantity' => 'SUM(quantity)', 'consumption_count' => 'COUNT(*)'];
    }
}
