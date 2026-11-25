<?php

namespace App\Actions\CustomersAndAccounts;

use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
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
            'amount' => ['required', 'string'], 'payment_method' => ['required', 'in:cash,transfer,check,other'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_event_id' => ['sometimes', 'uuid'],
            'occurred_at' => ['sometimes', 'date'],
            'delivery_stop_id' => $delivery === null ? ['prohibited'] : ['sometimes', 'required', 'integer', 'min:1'],
        ])->validate();
        $data['amount'] = CustomerAmounts::decimal(CustomerAmounts::parse($data['amount']));
        if (isset($data['delivery_stop_id'])) {
            $data['delivery_stop_id'] = (int) $data['delivery_stop_id'];
        }
        if ($delivery !== null && strtolower($data['client_event_id'] ?? '') !== strtolower($key)) {
            throw new CustomerAccountConflict('La identidad del cobro debe coincidir con su clave de idempotencia.');
        }

        return $this->operations->run('payment', $key, ['customer' => $customer->public_id, 'delivery' => $delivery?->public_id, 'data' => $data],
            $actor, $delivery === null ? 'pay' : 'can_update_own_deliveries',
            function (CustomerOperation $operation, User $actor) use ($customer, $data, $delivery): array {
                if ($delivery !== null) {
                    $currentDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                    if ($currentDelivery->driver_id !== $actor->id) {
                        throw new AuthorizationException;
                    }
                    if ($currentDelivery->status !== DeliveryStatus::Active) {
                        throw new CustomerAccountConflict('El reparto ya está cerrado.');
                    }
                    if (isset($data['delivery_stop_id'])) {
                        $stop = $currentDelivery->stops()->where('customer_id', $customer->id)
                            ->lockForUpdate()->findOrFail($data['delivery_stop_id']);
                        if ($stop->status !== DeliveryStopStatus::Delivered || $stop->accounting_status !== 'posted') {
                            throw new CustomerAccountConflict('El cobro debe vincularse a una entrega realizada y contabilizada.');
                        }
                    }
                    $operation->delivery_id = $currentDelivery->id;
                }
                $current = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $operation->customer_id = $current->id;
                $movement = $this->ledger->post($current, $operation, $actor, 'payment', '-'.$data['amount'], [
                    'reason' => $data['reason'] ?? null, 'payment_method' => $data['payment_method'],
                    'payment_reference' => $data['payment_reference'] ?? null,
                    'occurred_at' => $data['occurred_at'] ?? now(),
                    'delivery_stop_id' => $data['delivery_stop_id'] ?? null,
                ]);

                return ['movement_ids' => [$movement->id]];
            });
    }
}
