<?php

namespace App\Services\Deliveries;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DeliveriesReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('deliveries.deliveries', 'Reparto', 'Una fila por entrega confirmada. Valor de mercadería y cantidades reales; los cobros y la deuda requieren su registro en el módulo financiero.', 'delivery.history', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'delivery_id' => ['label' => 'Reparto', 'type' => 'string'],
            'delivery' => ['label' => 'Reparto', 'type' => 'string'], 'client_id' => ['label' => 'Cliente', 'type' => 'string'], 'client' => ['label' => 'Cliente', 'type' => 'string'],
            'quantity' => ['label' => 'Huevos entregados', 'type' => 'quantity', 'unit' => 'huevos'],
            'presentations' => ['label' => 'Presentaciones y cantidades', 'type' => 'string'],
            'sizes' => ['label' => 'Tipos / tamaños registrados', 'type' => 'string'],
            'merchandise_value' => ['label' => 'Valor de mercadería', 'type' => 'money', 'unit' => 'UYU'], 'notes' => ['label' => 'Comentarios', 'type' => 'string'],
        ], ['egg_count' => ['label' => 'Huevos entregados', 'type' => 'quantity', 'unit' => 'huevos'],
            'merchandise_total' => ['label' => 'Valor de mercadería', 'type' => 'money', 'unit' => 'UYU']]);
    }

    protected function dimensionKeys(): array
    {
        return ['delivery' => 'delivery_id', 'client' => 'client_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('delivery_stops as s')->join('deliveries as d', 'd.id', '=', 's.delivery_id')->where('s.status', 'delivered')
            ->selectRaw('s.id as row_key, s.visited_at as date, d.public_id as delivery_id, d.public_id as delivery, s.client_reference as client_id, s.client_name as client, s.delivered_quantity as quantity, s.total_amount as merchandise_value, s.notes')
            ->selectRaw("(SELECT string_agg(COALESCE(item->>'amount', '') || ' ' || COALESCE(item->>'label', item->>'unit', ''), ', ') FROM jsonb_array_elements(COALESCE(s.items::jsonb, '[]'::jsonb)) item) as presentations")
            ->selectRaw("(SELECT string_agg(DISTINCT COALESCE(item->>'classification', item->>'egg_type', item->>'type', ''), ', ') FROM jsonb_array_elements(COALESCE(s.items::jsonb, '[]'::jsonb)) item) as sizes");
    }

    protected function metricExpressions(): array
    {
        return ['egg_count' => 'SUM(quantity)', 'merchandise_total' => 'SUM(merchandise_value)'];
    }
}
