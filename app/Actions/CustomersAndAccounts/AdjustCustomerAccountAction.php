<?php

namespace App\Actions\CustomersAndAccounts;

use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerAccountLedger;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use Illuminate\Support\Facades\Validator;

final readonly class AdjustCustomerAccountAction
{
    public function __construct(private CustomerOperationRunner $operations, private CustomerAccountLedger $ledger) {}

    public function execute(Customer $customer, User $actor, array $data, string $key): CustomerOperation
    {
        $data['reason'] = is_string($data['reason'] ?? null) ? trim($data['reason']) : ($data['reason'] ?? null);
        $data = Validator::make($data, ['direction' => ['required', 'in:increase,decrease'], 'amount' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'], 'expected_version' => ['required', 'integer', 'min:0']])->validate();
        $data['amount'] = CustomerAmounts::decimal(CustomerAmounts::parse($data['amount']));
        $data['expected_version'] = (int) $data['expected_version'];

        return $this->operations->run('adjustment', $key, ['customer' => $customer->public_id, 'data' => $data], $actor, 'adjust',
            function (CustomerOperation $operation, User $actor) use ($customer, $data): array {
                $current = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $account = $this->ledger->account($current);
                if ($account->version !== $data['expected_version']) {
                    throw new CustomerAccountConflict('La cuenta cambió. Actualizá el saldo y confirmá nuevamente el ajuste.');
                }
                $operation->customer_id = $current->id;
                $movement = $this->ledger->post($current, $operation, $actor, 'adjustment_'.$data['direction'],
                    ($data['direction'] === 'decrease' ? '-' : '').$data['amount'], ['reason' => $data['reason']]);

                return ['movement_ids' => [$movement->id]];
            });
    }
}
