<?php

namespace App\Services\CustomersAndAccounts;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CustomerAccountReportSource extends SqlReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return $this->describe('accounts.movements', 'Cuenta corriente completa', 'Todos los cargos, cobros, ajustes y reversiones, incluso fuera de repartos. Saldos históricos por movimiento y saldo actual separado.', 'can_view_customer_accounts', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'],
            'customer_id' => ['label' => 'Cliente', 'type' => 'string'], 'customer' => ['label' => 'Cliente registrado', 'type' => 'string'],
            'movement_id' => ['label' => 'Movimiento', 'type' => 'string'], 'sequence' => ['label' => 'Secuencia', 'type' => 'integer'],
            'type' => ['label' => 'Tipo de movimiento', 'type' => 'string'],
            'amount' => ['label' => 'Importe firmado', 'type' => 'money', 'unit' => 'currency'],
            'currency' => ['label' => 'Moneda', 'type' => 'string'],
            'balance_before' => ['label' => 'Saldo anterior', 'type' => 'money', 'unit' => 'currency'],
            'balance_after' => ['label' => 'Saldo posterior', 'type' => 'money', 'unit' => 'currency'],
            'current_balance' => ['label' => 'Saldo actual de la cuenta', 'type' => 'money', 'unit' => 'UYU'],
            'payment_method' => ['label' => 'Forma de pago', 'type' => 'string'],
            'payment_reference' => ['label' => 'Referencia del pago', 'type' => 'string'],
            'delivery_id' => ['label' => 'Reparto', 'type' => 'string'],
            'reverses_movement_id' => ['label' => 'Revierte movimiento', 'type' => 'string'],
            'responsible' => ['label' => 'Responsable registrado', 'type' => 'string'], 'reason' => ['label' => 'Motivo', 'type' => 'string'],
        ], ['net_amount' => ['label' => 'Variación neta del saldo', 'type' => 'money', 'unit' => 'currency'],
            'movement_count' => ['label' => 'Movimientos', 'type' => 'count']], extraFilters: [
                'type' => ['label' => 'Tipo', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Cargo por venta', 'Cobro', 'Ajuste a débito', 'Ajuste a crédito', 'Reversión']],
                'payment_method' => ['label' => 'Forma de pago', 'type' => 'enum', 'operators' => ['eq', 'in'], 'options' => ['Efectivo', 'Transferencia', 'Cheque', 'Otro']],
            ], standardQueries: [['label' => 'Movimientos por mes y cliente', 'configuration' => ['groupings' => ['month', 'customer'], 'metrics' => ['net_amount', 'movement_count']]]], supportsPublicLinks: false);
    }

    protected function dimensionKeys(): array
    {
        return ['customer' => 'customer_id'];
    }

    protected function baseQuery(): Builder
    {
        return DB::table('customer_account_movements as m')->join('customer_accounts as account', 'account.id', '=', 'm.customer_account_id')
            ->join('customers as customer', 'customer.id', '=', 'account.customer_id')
            ->leftJoin('deliveries as delivery', 'delivery.id', '=', 'm.delivery_id')
            ->leftJoin('customer_account_movements as original', 'original.id', '=', 'm.reverses_movement_id')
            ->selectRaw("m.id as row_key, m.occurred_at as date, customer.public_id as customer_id, m.customer_name as customer, m.public_id as movement_id, m.sequence,
                CASE m.type WHEN 'sale_charge' THEN 'Cargo por venta' WHEN 'payment' THEN 'Cobro' WHEN 'adjustment_increase' THEN 'Ajuste a débito' WHEN 'adjustment_decrease' THEN 'Ajuste a crédito' WHEN 'reversal' THEN 'Reversión' END as type,
                m.amount, m.currency, m.balance_before, m.balance_after, account.balance as current_balance,
                CASE m.payment_method WHEN 'cash' THEN 'Efectivo' WHEN 'transfer' THEN 'Transferencia' WHEN 'check' THEN 'Cheque' WHEN 'other' THEN 'Otro' END as payment_method,
                m.payment_reference, delivery.public_id as delivery_id, original.public_id as reverses_movement_id, m.actor_name as responsible, m.reason");
    }

    protected function metricExpressions(): array
    {
        return ['net_amount' => 'SUM(amount)', 'movement_count' => 'COUNT(*)'];
    }
}
