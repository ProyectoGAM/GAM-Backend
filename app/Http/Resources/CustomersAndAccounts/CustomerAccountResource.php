<?php

namespace App\Http\Resources\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\CustomerAccount;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerAccount */
final class CustomerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $balance = CustomerAmounts::parse((string) $this->balance, allowZero: true, signed: true);

        return ['balance' => (string) $balance->getAmount(), 'debt' => $balance->isNegative() ? '0.000' : (string) $balance->getAmount(),
            'credit' => $balance->isNegative() ? (string) $balance->abs()->getAmount() : '0.000',
            'currency' => 'UYU', 'version' => (int) $this->version, 'as_of' => now()->toIso8601String()];
    }
}
