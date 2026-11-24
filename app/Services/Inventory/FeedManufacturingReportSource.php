<?php

namespace App\Services\Inventory;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FeedManufacturingReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('inventory.feed-manufacturing', 'Elaboraciones de ración', 'Una fila por elaboración, con receta e ingredientes históricos, distribución y estado vigente. Las canceladas no suman producción efectiva.', 'can_view_feed_stock', [
            'date' => ['label' => 'Fecha de elaboración', 'type' => 'datetime'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'], 'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
            'feed_recipe_id' => ['label' => 'Receta', 'type' => 'integer'], 'feed_recipe' => ['label' => 'Receta registrada', 'type' => 'string'],
            'poultry_house_id' => ['label' => 'Planta seleccionada', 'type' => 'integer'], 'poultry_house' => ['label' => 'Planta seleccionada', 'type' => 'string'],
            'operation_id' => ['label' => 'Operación', 'type' => 'string'], 'factor' => ['label' => 'Multiplicador de receta', 'type' => 'integer'],
            'quantity' => ['label' => 'Cantidad elaborada', 'type' => 'quantity', 'unit' => 'kg'],
            'status' => ['label' => 'Estado vigente', 'type' => 'string'],
            'cancelled_at' => ['label' => 'Fecha de cancelación', 'type' => 'datetime'],
            'cancellation_reason' => ['label' => 'Motivo de cancelación', 'type' => 'string'],
            'ingredients' => ['label' => 'Ingredientes y cantidades (kg)', 'type' => 'string'],
            'distribution' => ['label' => 'Distribución por planta (kg)', 'type' => 'string'],
            'shortage_quantity' => ['label' => 'Faltantes confirmados', 'type' => 'quantity', 'unit' => 'kg'],
        ], ['manufacturing_count' => ['label' => 'Elaboraciones registradas', 'type' => 'count'],
            'effective_quantity' => ['label' => 'Producción no cancelada', 'type' => 'quantity', 'unit' => 'kg']], extraFilters: [
                'status' => ['label' => 'Estado', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Elaborada', 'Cancelada']],
            ], standardQueries: [['label' => 'Producción por mes y receta', 'configuration' => ['groupings' => ['month', 'production_unit', 'feed_recipe'], 'metrics' => ['effective_quantity', 'manufacturing_count']]]],
            module: ['key' => 'feed_manufacturing', 'label' => 'Elaboración de ración']);
    }

    protected function dimensionKeys(): array
    {
        return [...parent::dimensionKeys(), 'feed_recipe' => 'feed_recipe_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('feed_stock_operations as operation')->join('production_units as u', 'u.id', '=', 'operation.production_unit_id')
            ->leftJoin('feed_stock_operations as cancellation', 'cancellation.cancels_operation_id', '=', 'operation.id')
            ->where('operation.kind', 'manufacture')
            ->selectRaw("operation.id as row_key, operation.occurred_at as date, operation.production_unit_id, u.name as production_unit,
                operation.feed_recipe_id, operation.snapshot->>'recipe_name' as feed_recipe, operation.poultry_house_id, operation.snapshot->>'plant_name' as poultry_house,
                operation.operation_id, operation.factor, operation.quantity_grams::numeric / 1000 as quantity,
                CASE WHEN cancellation.id IS NULL THEN 'Elaborada' ELSE 'Cancelada' END as status, cancellation.occurred_at as cancelled_at, cancellation.reason as cancellation_reason")
            ->selectRaw("(SELECT string_agg((item->>'name') || ': ' || ((item->>'required_quantity_grams')::numeric / 1000)::text, '; ' ORDER BY position)
                FROM jsonb_array_elements(COALESCE(operation.snapshot->'ingredients', '[]'::jsonb)) WITH ORDINALITY AS lines(item, position)) as ingredients")
            ->selectRaw("(SELECT string_agg((item->>'plant_name') || ': ' || ((item->>'quantity_grams')::numeric / 1000)::text, '; ' ORDER BY position)
                FROM jsonb_array_elements(COALESCE(operation.snapshot->'distribution', '[]'::jsonb)) WITH ORDINALITY AS lines(item, position)) as distribution")
            ->selectRaw("CASE WHEN jsonb_exists(operation.snapshot, 'shortages') THEN
                (SELECT COALESCE(SUM((item->>'quantity_grams')::numeric), 0) / 1000 FROM jsonb_array_elements(operation.snapshot->'shortages') item) END as shortage_quantity");
    }

    protected function metricExpressions(): array
    {
        return ['manufacturing_count' => 'COUNT(*)', 'effective_quantity' => "SUM(CASE WHEN status = 'Elaborada' THEN quantity ELSE 0 END)"];
    }
}
