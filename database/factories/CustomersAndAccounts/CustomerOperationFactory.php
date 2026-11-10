<?php

namespace Database\Factories\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomerOperation> */
class CustomerOperationFactory extends Factory
{
    public function definition(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'created_by' => User::factory(),
            'idempotency_key' => (string) Str::uuid(), 'kind' => 'payment', 'request_hash' => hash('sha256', 'fixture'), 'result' => []];
    }
}
