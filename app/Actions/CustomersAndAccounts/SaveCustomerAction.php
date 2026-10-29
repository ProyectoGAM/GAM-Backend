<?php

namespace App\Actions\CustomersAndAccounts;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccount;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerFields;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use App\Services\FarmStructure\UruguayBoundary;
use Illuminate\Support\Str;

final readonly class SaveCustomerAction
{
    public function __construct(private CustomerOperationRunner $operations, private AuditRecorder $audit, private UruguayBoundary $boundary) {}

    /** @param array<string, mixed> $data */
    public function execute(?Customer $customer, User $actor, array $data, string $key): CustomerOperation
    {
        $data = CustomerFields::normalize($data);

        return $this->operations->run($customer === null ? 'customer_create' : 'customer_update', $key,
            ['customer' => $customer?->public_id, 'data' => $data], $actor, 'manage',
            function (CustomerOperation $operation, User $actor) use ($customer, $data): array {
                $current = $customer === null ? new Customer : Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $before = $current->exists ? $current->only(array_keys(CustomerFields::rules())) : [];
                $attributes = CustomerFields::validate([...$before, ...$data], $this->boundary);
                $current->fill($attributes);
                if (! $current->exists) {
                    $current->forceFill(['public_id' => (string) Str::ulid(), 'status' => 'active', 'created_by' => $actor->id]);
                }
                $created = ! $current->exists;
                $current->save();
                if ($created) {
                    $account = new CustomerAccount;
                    $account->forceFill(['customer_id' => $current->id, 'balance' => '0.000', 'version' => 0])->save();
                }
                $operation->customer_id = $current->id;
                $this->audit->record(AuditEntryData::forSubject(subject: $current, actor: $actor,
                    logName: 'customers_and_accounts', event: $created ? 'customer_created' : 'customer_updated',
                    description: $created ? 'Cliente registrado' : 'Datos del cliente actualizados', operationId: $operation->operation_id,
                    properties: ['subject_snapshot' => ['id' => $current->public_id, 'name' => $current->displayName(), 'status' => $current->status->value]],
                    attributeChanges: ['old' => array_intersect_key($before, array_flip(['name', 'business_name', 'address', 'latitude', 'longitude', 'visit_frequency', 'commercial_conditions', 'notes', 'delivery_notes'])),
                        'new' => $current->only(['name', 'business_name', 'address', 'latitude', 'longitude', 'visit_frequency', 'commercial_conditions', 'notes', 'delivery_notes'])]));

                return ['customer' => $current->attributesToArray()];
            });
    }
}
