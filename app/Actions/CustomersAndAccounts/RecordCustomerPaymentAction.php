<?php

namespace App\Actions\CustomersAndAccounts;

use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Deliveries\Delivery;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerAccountLedger;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

final readonly class RecordCustomerPaymentAction
{
    public function __construct(private CustomerOperationRunner $operations, private CustomerAccountLedger $ledger) {}

    public function execute(Customer $customer, User $actor, array $data, string $key, ?Delivery $delivery = null): CustomerOperation
    {
        $data = Validator::make($data, [
            'amount' => ['required', 'string'], 'payment_method' => ['required', 'in:cash,transfer,other'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_event_id' => ['sometimes', 'uuid'],
        ])->validate();
        $data['amount'] = CustomerAmounts::decimal(CustomerAmounts::parse($data['amount']));
        if ($delivery !== null && strtolower($data['client_event_id'] ?? '') !== strtolower($key)) {
            throw new CustomerAccountConflict('La identidad del cobro debe coincidir con su clave de idempotencia.');
        }

        return $this->operations->run('payment', $key, ['customer' => $customer->public_id, 'delivery' => $delivery?->public_id, 'data' => $data],
            $actor, $delivery === null ? 'pay' : 'delivery.update-own',
            function (CustomerOperation $operation, User $actor) use ($customer, $data, $delivery): array {
                if ($delivery !== null) {
                    $currentDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                    if ($currentDelivery->driver_id !== $actor->id) {
                        throw new AuthorizationException;
                    }
                    if ($currentDelivery->status !== DeliveryStatus::Active) {
                        throw new CustomerAccountConflict('El reparto ya está cerrado.');
                    }
                    $operation->delivery_id = $currentDelivery->id;
                }
                $current = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $operation->customer_id = $current->id;
                $movement = $this->ledger->post($current, $operation, $actor, 'payment', '-'.$data['amount'], [
                    'reason' => $data['reason'] ?? null, 'payment_method' => $data['payment_method'],
                    'payment_reference' => $data['payment_reference'] ?? null,
                ]);

                return ['movement_ids' => [$movement->id]];
            });
    }
}
