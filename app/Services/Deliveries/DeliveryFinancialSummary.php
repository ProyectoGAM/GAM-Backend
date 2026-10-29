<?php

namespace App\Services\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Models\Deliveries\Delivery;
use App\Services\CustomersAndAccounts\CustomerAmounts;

final readonly class DeliveryFinancialSummary
{
    public function __construct(private DeliveryPricing $pricing) {}

    public function execute(Delivery $delivery): array
    {
        $zero = CustomerAmounts::parse('0.000', allowZero: true);
        $paid = $zero;
        $delta = $zero;
        $methods = ['cash' => $zero, 'transfer' => $zero, 'other' => $zero];
        $customers = [];
        foreach ($delivery->accountMovements->sortBy('id') as $movement) {
            /** @var CustomerAccountMovement $movement */
            $amount = CustomerAmounts::parse($movement->amount, signed: true);
            $delta = $delta->plus($amount);
            $customerId = $movement->account->customer->public_id;
            $customers[$customerId] ??= ['customer_id' => $customerId, 'customer_name' => $movement->customer_name,
                'merchandise_amount' => $zero, 'collected_amount' => $zero, 'account_delta' => $zero,
                'balance_before' => $movement->balance_before, 'balance_after' => $movement->balance_after];
            $row = &$customers[$customerId];
            $row['account_delta'] = $row['account_delta']->plus($amount);
            $row['balance_after'] = $movement->balance_after;
            if ($movement->type->value === 'sale_charge') {
                $row['merchandise_amount'] = $row['merchandise_amount']->plus($amount);
            }
            if ($movement->type->value === 'payment' || ($movement->type->value === 'reversal' && $movement->payment_method !== null)) {
                $collected = $amount->negated();
                $paid = $paid->plus($collected);
                $methods[$movement->payment_method] = $methods[$movement->payment_method]->plus($collected);
                $row['collected_amount'] = $row['collected_amount']->plus($collected);
            }
            unset($row);
        }
        $legacy = $delivery->stops->where('status', DeliveryStopStatus::Delivered)
            ->where('accounting_status', 'legacy_unreconciled')->count();
        $rows = array_map(function (array $row): array {
            foreach (['merchandise_amount', 'collected_amount', 'account_delta'] as $key) {
                $row[$key] = (string) $row[$key]->getAmount();
            }

            return $row;
        }, array_values($customers));

        return ['currency' => 'UYU', 'merchandise_amount' => $this->pricing->total($delivery->stops),
            'collected_amount' => $legacy > 0 ? null : (string) $paid->getAmount(),
            'recorded_collected_amount' => (string) $paid->getAmount(), 'account_delta' => (string) $delta->getAmount(),
            'by_method' => array_map(fn ($amount) => (string) $amount->getAmount(), $methods),
            'legacy_unreconciled_count' => $legacy, 'customers' => $rows];
    }
}
