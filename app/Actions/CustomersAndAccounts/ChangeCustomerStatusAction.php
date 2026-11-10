<?php

namespace App\Actions\CustomersAndAccounts;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use Illuminate\Validation\ValidationException;

final readonly class ChangeCustomerStatusAction
{
    public function __construct(private CustomerOperationRunner $operations, private AuditRecorder $audit) {}

    public function execute(Customer $customer, User $actor, string $status, string $key): CustomerOperation
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'El estado debe ser activo o inactivo.']);
        }

        return $this->operations->run('customer_status', $key, ['customer' => $customer->public_id, 'status' => $status], $actor, 'manage',
            function (CustomerOperation $operation, User $actor) use ($customer, $status): array {
                $current = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $before = $current->status->value;
                $current->forceFill(['status' => $status])->save();
                $operation->customer_id = $current->id;
                $this->audit->record(AuditEntryData::forSubject(subject: $current, actor: $actor,
                    logName: 'customers_and_accounts', event: 'customer_status_changed', description: 'Estado del cliente actualizado',
                    operationId: $operation->operation_id, properties: ['subject_snapshot' => ['id' => $current->public_id, 'name' => $current->displayName()]],
                    attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status]]));

                return ['customer' => $current->attributesToArray()];
            });
    }
}
