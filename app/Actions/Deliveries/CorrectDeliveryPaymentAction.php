<?php

namespace App\Actions\Deliveries;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\CustomersAndAccounts\CustomerMovementType;
use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Deliveries\Delivery;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerAccountLedger;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CorrectDeliveryPaymentAction
{
    public function __construct(
        private CustomerOperationRunner $operations,
        private CustomerAccountLedger $ledger,
        private AuditRecorder $audit,
    ) {}

    public function execute(Delivery $delivery, string $paymentId, User $actor, string $amount, string $reason, string $key): CustomerOperation
    {
        if ($delivery->driver_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $amount = CustomerAmounts::decimal(CustomerAmounts::parse($amount, allowZero: true));
        $reason = trim($reason);

        return $this->operations->run(CustomerOperation::DELIVERY_PAYMENT_CORRECTION, $key,
            ['delivery' => $delivery->public_id, 'payment' => $paymentId, 'amount' => $amount, 'reason' => $reason], $actor, 'can_update_own_deliveries',
            function (CustomerOperation $operation, User $actor) use ($delivery, $paymentId, $amount, $reason): array {
                $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                if ($delivery->driver_id !== $actor->id) {
                    throw new AuthorizationException;
                }
                if ($delivery->status !== DeliveryStatus::Active) {
                    throw new CustomerAccountConflict('Solo podés rectificar cobros de un reparto activo.');
                }
                $original = $delivery->accountMovements()->where('public_id', $paymentId)
                    ->where('type', CustomerMovementType::Payment)->with('account')->firstOrFail();
                $customer = Customer::query()->whereKey($original->account->customer_id)->lockForUpdate()->firstOrFail();
                $this->ledger->account($customer);

                $operation->customer_id = $customer->id;
                $operation->delivery_id = $delivery->id;
                $reversal = $this->ledger->reverse($customer, $operation, $actor, $original, $reason);
                $replacement = null;
                if ($amount !== '0.000') {
                    $replacement = $this->ledger->post($customer, $operation, $actor, 'payment', '-'.$amount, [
                        'delivery_stop_id' => $original->delivery_stop_id,
                        'payment_method' => $original->payment_method,
                        'payment_reference' => $original->payment_reference,
                        'occurred_at' => $original->occurred_at,
                        'reason' => $reason,
                    ]);
                }
                $this->audit->record(AuditEntryData::forSubject(
                    subject: $original, actor: $actor, logName: 'deliveries', event: 'delivery_payment_corrected',
                    description: 'Cobro de reparto rectificado', operationId: $operation->operation_id,
                    upId: $delivery->production_unit_id, source: 'delivery',
                    properties: [
                        'delivery_id' => $delivery->public_id, 'delivery_stop_id' => $original->delivery_stop_id,
                        'customer_id' => $customer->public_id, 'reason' => $reason, 'result' => 'success',
                        'reversal_id' => $reversal->public_id, 'replacement_id' => $replacement?->public_id,
                    ],
                    attributeChanges: ['old' => ['amount' => ltrim($original->amount, '-')], 'new' => ['amount' => $amount]],
                ));

                return ['movement_ids' => $replacement === null ? [$reversal->id] : [$reversal->id, $replacement->id]];
            });
    }
}
