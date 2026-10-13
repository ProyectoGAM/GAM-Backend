<?php

namespace App\Services\Inventory;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EggOutflowReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('commerce.egg-outflow', 'Ventas y donaciones', 'Entregas comerciales confirmadas y donaciones registradas, sin cargas, pérdidas, ajustes ni retornos. Selecciona Venta para excluir donaciones o todos los tipos para incluirlas.', 'delivery.history', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'kind' => ['label' => 'Tipo de salida', 'type' => 'string'],
            'recipient' => ['label' => 'Cliente / destinatario', 'type' => 'string'], 'quantity' => ['label' => 'Huevos', 'type' => 'quantity', 'unit' => 'huevos'],
            'notes' => ['label' => 'Comentarios', 'type' => 'string'],
        ], ['sold_eggs' => ['label' => 'Vendidos', 'type' => 'quantity', 'unit' => 'huevos'],
            'donated_eggs' => ['label' => 'Donados', 'type' => 'quantity', 'unit' => 'huevos'],
            'total_eggs' => ['label' => 'Vendidos más donados', 'type' => 'quantity', 'unit' => 'huevos']], [
                'kind' => ['label' => 'Incluir donaciones', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Venta', 'Donación']],
            ], [['label' => 'Sólo volumen vendido', 'configuration' => ['filters' => [['field' => 'kind', 'operator' => 'eq', 'value' => 'Venta']], 'metrics' => ['sold_eggs']]],
                ['label' => 'Volumen vendido y donado', 'configuration' => ['metrics' => ['sold_eggs', 'donated_eggs', 'total_eggs']]]], ['egg-stock.view']);
    }

    protected function baseQuery(): Builder
    {
        $sales = DB::table('delivery_stops as s')->where('s.status', 'delivered')
            ->selectRaw("'sale-' || s.id as row_key, s.visited_at as date, 'Venta' as kind, s.client_name as recipient, s.delivered_quantity as quantity, s.notes");
        $donations = DB::table('egg_stock_transactions as t')->where('t.type', 'donation')->where('t.status', 'recorded')
            ->selectRaw("'donation-' || t.id as row_key, t.occurred_at as date, 'Donación' as kind, t.reason as recipient, t.quantity, t.notes");

        return $sales->unionAll($donations);
    }

    protected function metricExpressions(): array
    {
        return ['sold_eggs' => "SUM(CASE WHEN kind = 'Venta' THEN quantity ELSE 0 END)",
            'donated_eggs' => "SUM(CASE WHEN kind = 'Donación' THEN quantity ELSE 0 END)", 'total_eggs' => 'SUM(quantity)'];
    }
}
