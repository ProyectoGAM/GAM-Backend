<?php

namespace App\Queries\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use Carbon\CarbonImmutable;

final class CustomerAccountHistoryQuery
{
    public function execute(Customer $customer, array $filters): array
    {
        $account = $customer->account()->firstOrFail();
        // La versión limita todas las consultas al mismo estado confirmado de la cuenta.
        $query = CustomerAccountMovement::query()->where('customer_account_id', $account->id)->where('sequence', '<=', $account->version);
        $from = empty($filters['from']) ? null : CarbonImmutable::parse($filters['from'], 'America/Montevideo')->startOfDay()->utc();
        $to = empty($filters['to']) ? null : CarbonImmutable::parse($filters['to'], 'America/Montevideo')->endOfDay()->utc();
        $opening = $from === null ? '0.000' : (string) (clone $query)->where('created_at', '<', $from)->sum('amount');
        $closing = (string) (clone $query)->when($to, fn ($q) => $q->where('created_at', '<=', $to))->sum('amount');
        $rows = $query->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->with(['account.customer', 'operation', 'delivery', 'reversal', 'original'])->orderByDesc('sequence')
            ->paginate($filters['per_page'] ?? 50)->withQueryString();

        return ['rows' => $rows, 'statement' => ['opening_balance' => CustomerAmounts::decimal(CustomerAmounts::parse($opening, allowZero: true, signed: true)),
            'closing_balance' => CustomerAmounts::decimal(CustomerAmounts::parse($closing, allowZero: true, signed: true)),
            'currency' => 'UYU', 'version' => $account->version, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]];
    }
}
