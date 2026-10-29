<?php

namespace App\Services\CustomersAndAccounts;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccount;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Deliveries\Delivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

final readonly class CustomerAccountLedger
{
    public function __construct(private AuditRecorder $audit) {}

    /** El llamador conserva el lock del cliente dentro de la transacción de su operación. */
    public function account(Customer $customer): CustomerAccount
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La cuenta requiere una transacción de negocio.');
        }

        return CustomerAccount::query()->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function post(Customer $customer, CustomerOperation $operation, User $actor, string $type, string $amount, array $attributes = []): CustomerAccountMovement
    {
        if ($actor->trashed() || $operation->created_by !== $actor->id) {
            throw new AuthorizationException;
        }
        if (in_array($type, ['sale_charge', 'payment'], true) && $operation->delivery_id !== null) {
            $delivery = Delivery::query()->whereKey($operation->delivery_id)->lockForUpdate()->firstOrFail();
            if ($delivery->driver_id !== $actor->id || ! $actor->can('delivery.update-own')) {
                throw new AuthorizationException;
            }
            if ($delivery->status !== DeliveryStatus::Active) {
                throw new CustomerAccountConflict('El reparto ya está cerrado.');
            }
        } else {
            $ability = match ($type) {
                'payment' => 'pay', 'adjustment_increase', 'adjustment_decrease' => 'adjust', 'reversal' => 'reverse',
                default => throw new AuthorizationException,
            };
            Gate::forUser($actor)->authorize($ability, Customer::class);
        }
        $value = CustomerAmounts::parse($amount, signed: true);
        $positive = in_array($type, ['sale_charge', 'adjustment_increase'], true);
        if (($type !== 'reversal') && ($positive === $value->isNegative())) {
            throw new LogicException('El signo no corresponde al tipo de movimiento.');
        }
        $account = $this->account($customer);
        $before = CustomerAmounts::parse($account->balance, allowZero: true, signed: true);
        $after = CustomerAmounts::decimal($before->plus($value));
        $movement = new CustomerAccountMovement;
        $movement->forceFill([
            ...$attributes, 'public_id' => (string) Str::ulid(), 'customer_account_id' => $account->id,
            'customer_operation_id' => $operation->id, 'sequence' => $account->version + 1, 'type' => $type,
            'amount' => CustomerAmounts::decimal($value), 'balance_before' => $account->balance, 'balance_after' => $after,
            'customer_name' => $customer->displayName(), 'currency' => 'UYU', 'created_by' => $actor->id, 'actor_name' => $actor->name,
            'delivery_id' => $operation->delivery_id, 'occurred_at' => $attributes['occurred_at'] ?? now(),
        ])->save();
        $account->forceFill(['balance' => $after, 'version' => $movement->sequence])->save();
        $this->audit->record(AuditEntryData::forSubject(subject: $movement, actor: $actor, logName: 'customers_and_accounts',
            event: 'customer_account_'.$type, description: 'Movimiento de cuenta corriente registrado',
            operationId: $operation->operation_id, source: $operation->delivery_id === null ? 'api' : 'delivery',
            properties: ['subject_snapshot' => ['id' => $movement->public_id, 'customer_id' => $customer->public_id,
                'customer_name' => $customer->displayName(), 'type' => $type, 'amount' => $movement->amount,
                'currency' => 'UYU', 'reason' => $movement->reason, 'delivery_id' => $operation->delivery_id,
                'delivery_stop_id' => $movement->delivery_stop_id, 'reverses_movement_id' => $movement->reverses_movement_id], 'result' => 'success'],
            attributeChanges: ['old' => ['balance' => $movement->balance_before], 'new' => ['balance' => $movement->balance_after]]));

        return $movement;
    }
}
