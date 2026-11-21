<?php

namespace App\Services\Inventory;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FeedBalancesReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('inventory.feed-balances', 'Stock de alimento por receta', 'Saldo vigente por receta y UP, con mínimos y atención. Incluye negativos y recetas inactivas. No representa existencias históricas.', 'can_view_feed_stock', [
            'date' => ['label' => 'Última actualización', 'type' => 'datetime'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'], 'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
            'feed_recipe_id' => ['label' => 'Receta', 'type' => 'integer'], 'feed_recipe' => ['label' => 'Receta', 'type' => 'string'],
            'quantity' => ['label' => 'Saldo actual', 'type' => 'quantity', 'unit' => 'kg'],
            'minimum_quantity' => ['label' => 'Mínimo configurado', 'type' => 'quantity', 'unit' => 'kg'],
            'status' => ['label' => 'Estado del saldo', 'type' => 'string'],
            'attention' => ['label' => 'Requiere atención', 'type' => 'boolean'],
        ], ['available_stock' => ['label' => 'Saldo total', 'type' => 'quantity', 'unit' => 'kg']], extraFilters: [
            'attention' => ['label' => 'Requiere atención', 'type' => 'boolean', 'operators' => ['eq']],
            'quantity' => ['label' => 'Saldo (kg)', 'type' => 'number', 'operators' => ['eq', 'lt', 'lte', 'gt', 'gte']],
            'status' => ['label' => 'Estado', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Sin configurar', 'Suficiente', 'En el mínimo', 'Por debajo del mínimo']],
        ], supportsDateRange: false, module: ['key' => 'feed_stock', 'label' => 'Stock de alimento']);
    }

    protected function dimensionKeys(): array
    {
        return [...parent::dimensionKeys(), 'feed_recipe' => 'feed_recipe_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('feed_balances as balance')->join('feed_recipes as recipe', 'recipe.id', '=', 'balance.feed_recipe_id')
            ->join('production_units as u', 'u.id', '=', 'balance.production_unit_id')
            ->selectRaw("balance.id as row_key, balance.updated_at as date, balance.production_unit_id, u.name as production_unit, balance.feed_recipe_id, recipe.name as feed_recipe,
                balance.quantity_grams::numeric / 1000 as quantity, balance.minimum_quantity_grams::numeric / 1000 as minimum_quantity,
                CASE WHEN balance.minimum_quantity_grams IS NULL THEN 'Sin configurar' WHEN balance.quantity_grams > balance.minimum_quantity_grams THEN 'Suficiente'
                    WHEN balance.quantity_grams = balance.minimum_quantity_grams THEN 'En el mínimo' ELSE 'Por debajo del mínimo' END as status,
                (balance.minimum_quantity_grams IS NOT NULL AND balance.quantity_grams <= balance.minimum_quantity_grams) as attention");
    }

    protected function metricExpressions(): array
    {
        return ['available_stock' => 'SUM(quantity)'];
    }
}
