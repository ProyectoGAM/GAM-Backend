<?php

namespace App\Actions\CustomersAndAccounts;

use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Deliveries\Delivery;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerAccountLedger;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use Illuminate\Support\Facades\Validator;

final readonly class ReverseCustomerMovementAction
{
    public function __construct(private CustomerOperationRunner $operations, private CustomerAccountLedger $ledger) {}

    public function execute(Customer $customer, CustomerAccountMovement $movement, User $actor, string $reason, string $key): CustomerOperation
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();

        return $this->operations->run('reversal', $key, ['customer' => $customer->public_id, 'movement' => $movement->public_id, 'reason' => $reason],
            $actor, 'reverse', function (CustomerOperation $operation, User $actor) use ($customer, $movement, $reason): array {
                if ($movement->delivery_id !== null) {
                    Delivery::query()->whereKey($movement->delivery_id)->lockForUpdate()->firstOrFail();
                    $operation->delivery_id = $movement->delivery_id;
                }
                $current = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $account = $this->ledger->account($current);
                $original = CustomerAccountMovement::query()->whereKey($movement->id)->where('customer_account_id', $account->id)->firstOrFail();
                if (! in_array($original->type->value, ['payment', 'adjustment_increase', 'adjustment_decrease'], true)) {
                    throw new CustomerAccountConflict('Este movimiento se corrige desde su operación de origen.');
                }
                if ($original->reversal()->exists()) {
                    throw new CustomerAccountConflict('El movimiento ya tiene una reversión.');
                }
                $operation->customer_id = $current->id;
                $reversal = $this->ledger->post($current, $operation, $actor, 'reversal',
                    CustomerAmounts::decimal(CustomerAmounts::parse($original->amount, signed: true)->negated()),
                    ['reason' => $reason, 'reverses_movement_id' => $original->id, 'payment_method' => $original->payment_method,
                        'delivery_stop_id' => $original->delivery_stop_id]);

                return ['movement_ids' => [$reversal->id]];
            });
    }
}
