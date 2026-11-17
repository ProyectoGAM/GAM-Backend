<?php

namespace App\Services\Deliveries;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DeliveryCollectionsReportSource extends DeliveriesReportSource
{
    public function definition(): ReportSourceDefinition
    {
        $columns = array_diff_key(parent::columns(), array_flip(['sizes']));
        $columns['notes']['label'] = 'Observaciones';
        $columns += [
            'trip_date' => ['label' => 'Día de inicio del reparto', 'type' => 'date'],
            'loaded_quantity' => ['label' => 'Huevos cargados en el reparto', 'type' => 'quantity', 'unit' => 'huevos'],
            'returned_quantity' => ['label' => 'Huevos sobrantes devueltos', 'type' => 'quantity', 'unit' => 'huevos'],
            'default_merchandise_value' => ['label' => 'Valor de mercadería al precio predeterminado', 'type' => 'money', 'unit' => 'UYU'],
            'entered_unit_prices' => ['label' => 'Precios ingresados por el repartidor', 'type' => 'string'],
            'paid_at_delivery' => ['label' => 'Cobrado en la entrega', 'type' => 'money', 'unit' => 'UYU'],
            'outstanding_at_delivery' => ['label' => 'Importe pendiente en la entrega', 'type' => 'money', 'unit' => 'UYU'],
            'payment_method' => ['label' => 'Forma de pago en la entrega', 'type' => 'string'],
            'payment_status' => ['label' => 'Estado del pago en la entrega', 'type' => 'string'],
            'current_debt' => ['label' => 'Deuda actual', 'type' => 'money', 'unit' => 'UYU'],
            'current_credit' => ['label' => 'Saldo a favor actual', 'type' => 'money', 'unit' => 'UYU'],
            'current_debt_status' => ['label' => 'Estado de la deuda actual', 'type' => 'string'],
        ];
        $filters = [];
        foreach (['quantity', 'loaded_quantity', 'returned_quantity', 'merchandise_value', 'default_merchandise_value',
            'paid_at_delivery', 'outstanding_at_delivery', 'current_debt', 'current_credit', 'trip_date'] as $field) {
            $filters[$field] = ['label' => $columns[$field]['label'], 'type' => $field === 'trip_date' ? 'date' : 'number',
                'operators' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte']];
        }
        foreach (['notes', 'client', 'presentations', 'entered_unit_prices'] as $field) {
            $filters[$field] = ['label' => $columns[$field]['label'], 'type' => 'string', 'operators' => ['contains']];
        }
        foreach ([
            'payment_method' => ['Efectivo', 'Transferencia', 'Cheque', 'Otro', 'Sin cobro', 'Desconocida'],
            'payment_status' => ['Fiado', 'Pago parcial', 'Pagado', 'Sin cargo', 'Desconocido'],
            'current_debt_status' => ['Con deuda', 'A favor', 'Al día', 'Desconocido'],
        ] as $field => $options) {
            $filters[$field] = ['label' => $columns[$field]['label'], 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => $options];
        }

        $totals = ['loaded_total', 'returned_total', 'egg_count', 'merchandise_total', 'default_merchandise_total',
            'collected_total', 'outstanding_total', 'current_debt_total', 'current_credit_total', 'unknown_payment_count'];

        return $this->describe('deliveries.collections', 'Reparto completo', 'Entregas, cargas, sobrantes, precios, cobros y deuda actual por cliente.', 'can_view_delivery_history',
            $columns, $this->metrics(), $filters, standardQueries: [
                ['label' => 'Detalle de entregas', 'configuration' => [
                    'columns' => array_values(array_diff(array_keys($columns), ['delivery_id', 'client_id'])),
                ]],
                ['label' => 'Resumen por día', 'configuration' => ['groupings' => ['day'], 'metrics' => $totals]],
                ['label' => 'Resumen por cliente', 'configuration' => ['groupings' => ['client', 'current_debt_status'], 'metrics' => $totals]],
            ], additionalPermissions: ['can_view_customer_accounts'], supportsPublicLinks: false);
    }

    protected function metrics(): array
    {
        return [...parent::metrics(),
            'loaded_total' => ['label' => 'Total de huevos cargados', 'type' => 'quantity', 'unit' => 'huevos'],
            'returned_total' => ['label' => 'Total de huevos sobrantes devueltos', 'type' => 'quantity', 'unit' => 'huevos'],
            'default_merchandise_total' => ['label' => 'Total al precio predeterminado', 'type' => 'money', 'unit' => 'UYU'],
            'collected_total' => ['label' => 'Total cobrado en las entregas', 'type' => 'money', 'unit' => 'UYU'],
            'outstanding_total' => ['label' => 'Total pendiente al entregar', 'type' => 'money', 'unit' => 'UYU'],
            'current_debt_total' => ['label' => 'Deuda actual', 'type' => 'money', 'unit' => 'UYU'],
            'current_credit_total' => ['label' => 'Saldo a favor actual de los clientes', 'type' => 'money', 'unit' => 'UYU'],
            'customers_with_debt' => ['label' => 'Clientes con deuda actual', 'type' => 'quantity', 'unit' => 'clientes'],
            'customers_with_credit' => ['label' => 'Clientes con saldo a favor actual', 'type' => 'quantity', 'unit' => 'clientes'],
            'customers_settled' => ['label' => 'Clientes al día', 'type' => 'quantity', 'unit' => 'clientes'],
            'unknown_payment_count' => ['label' => 'Entregas con cobro desconocido', 'type' => 'quantity', 'unit' => 'entregas'],
        ];
    }

    protected function baseQuery(): Builder
    {
        $atDelivery = DB::table('customer_account_movements as m')
            ->whereColumn('m.delivery_id', 'd.id')->whereColumn('m.delivery_stop_id', 's.id')
            ->whereIn('m.type', ['payment', 'sale_charge'])
            ->selectRaw("SUM(m.amount) FILTER (WHERE m.type = 'sale_charge') AS charge,
                -SUM(m.amount) FILTER (WHERE m.type = 'payment') AS paid,
                MAX(m.payment_method) FILTER (WHERE m.type = 'payment') AS method");

        return parent::baseQuery()
            ->leftJoin('customers as legacy', fn ($join) => $join->on('legacy.public_id', '=', 's.client_reference')->whereNull('s.customer_id'))
            ->leftJoin('customer_accounts as account', fn ($join) => $join->whereRaw('account.customer_id = COALESCE(s.customer_id, legacy.id)'))
            ->leftJoinLateral($atDelivery, 'visit')
            ->selectRaw('(d.started_at AT TIME ZONE ?)::date AS trip_date, d.loaded_quantity,
                CASE WHEN d.closed_at IS NOT NULL THEN d.returned_quantity END AS returned_quantity', [config('lots.timezone', 'America/Montevideo')])
            ->selectRaw("CASE WHEN s.accounting_status = 'posted' THEN COALESCE(visit.paid, 0.000) END AS paid_at_delivery,
                CASE WHEN s.accounting_status = 'posted' THEN GREATEST(COALESCE(visit.charge, 0.000) - COALESCE(visit.paid, 0.000), 0.000) END AS outstanding_at_delivery,
                CASE WHEN s.accounting_status <> 'posted' THEN 'Desconocida'
                     WHEN visit.method = 'cash' THEN 'Efectivo' WHEN visit.method = 'transfer' THEN 'Transferencia'
                     WHEN visit.method = 'check' THEN 'Cheque' WHEN visit.method = 'other' THEN 'Otro'
                     ELSE 'Sin cobro' END AS payment_method,
                CASE WHEN s.accounting_status <> 'posted' THEN 'Desconocido'
                     WHEN COALESCE(visit.charge, 0) = 0 AND COALESCE(visit.paid, 0) = 0 THEN 'Sin cargo'
                     WHEN COALESCE(visit.paid, 0) = 0 THEN 'Fiado'
                     WHEN visit.paid < visit.charge THEN 'Pago parcial' ELSE 'Pagado' END AS payment_status,
                CASE WHEN account.balance IS NOT NULL THEN GREATEST(account.balance, 0.000) END AS current_debt,
                CASE WHEN account.balance IS NOT NULL THEN GREATEST(-account.balance, 0.000) END AS current_credit,
                CASE WHEN account.balance > 0 THEN 'Con deuda' WHEN account.balance < 0 THEN 'A favor'
                     WHEN account.balance = 0 THEN 'Al día' ELSE 'Desconocido' END AS current_debt_status")
            ->selectRaw("(SELECT CASE WHEN COUNT(*) = COUNT(item->>'default_unit_price')
                        THEN SUM((item->>'amount')::numeric * (item->>'default_unit_price')::numeric)::numeric(24,3) END
                FROM jsonb_array_elements(COALESCE(s.items::jsonb, '[]'::jsonb)) item) AS default_merchandise_value")
            ->selectRaw("(SELECT string_agg(COALESCE(item->>'label', item->>'unit') || ': $' || (item->>'entered_unit_price'), ', ' ORDER BY position)
                FROM jsonb_array_elements(COALESCE(s.items::jsonb, '[]'::jsonb)) WITH ORDINALITY AS lines(item, position)) AS entered_unit_prices");
    }

    protected function prepareFilteredRows(Builder $rows, ReportQueryData $query): Builder
    {
        $tripMetrics = ['loaded_total', 'returned_total'];
        $customerMetrics = ['current_debt_total', 'current_credit_total', 'customers_with_debt', 'customers_with_credit', 'customers_settled'];
        $ranks = [];
        foreach (['delivery_id' => $tripMetrics, 'client_id' => $customerMetrics] as $entity => $metrics) {
            if (array_intersect($query->metrics, $metrics) === []) {
                continue;
            }
            // Numera cada entidad una vez dentro de cada grupo después de aplicar los filtros.
            $keys = [$entity, ...$query->groupings];
            foreach ($query->groupings as $grouping) {
                if (isset($this->dimensionKeys()[$grouping])) {
                    $keys[] = $this->dimensionKeys()[$grouping];
                }
            }
            $partition = implode(', ', array_map(fn (string $key): string => '"'.$key.'"', array_unique($keys)));
            $ranks[] = 'ROW_NUMBER() OVER (PARTITION BY '.$partition.' ORDER BY row_key) AS '.$entity.'_rank';
        }

        return $ranks === [] ? $rows : DB::query()->fromSub($rows->select('report_rows.*')->selectRaw(implode(', ', $ranks)), 'ranked_rows');
    }

    protected function query(ReportQueryData $query): Builder
    {
        $rows = parent::query($query);

        return $query->groupings === [] && $query->metrics !== [] ? DB::query()->fromSub($rows, 'complete_delivery_totals') : $rows;
    }

    protected function metricExpressions(): array
    {
        return [...parent::metricExpressions(),
            'loaded_total' => 'SUM(loaded_quantity) FILTER (WHERE delivery_id_rank = 1)',
            'returned_total' => 'SUM(returned_quantity) FILTER (WHERE delivery_id_rank = 1)',
            'default_merchandise_total' => 'SUM(default_merchandise_value)',
            'collected_total' => 'SUM(paid_at_delivery)',
            'outstanding_total' => 'SUM(outstanding_at_delivery)',
            'current_debt_total' => 'SUM(current_debt) FILTER (WHERE client_id_rank = 1)',
            'current_credit_total' => 'SUM(current_credit) FILTER (WHERE client_id_rank = 1)',
            'customers_with_debt' => "COUNT(*) FILTER (WHERE client_id_rank = 1 AND current_debt_status = 'Con deuda')",
            'customers_with_credit' => "COUNT(*) FILTER (WHERE client_id_rank = 1 AND current_debt_status = 'A favor')",
            'customers_settled' => "COUNT(*) FILTER (WHERE client_id_rank = 1 AND current_debt_status = 'Al día')",
            'unknown_payment_count' => 'COUNT(*) FILTER (WHERE paid_at_delivery IS NULL)',
        ];
    }
}
