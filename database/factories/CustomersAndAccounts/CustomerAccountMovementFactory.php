<?php

namespace Database\Factories\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\CustomerAccount;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Models\CustomersAndAccounts\CustomerOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomerAccountMovement> */
class CustomerAccountMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['public_id' => (string) Str::ulid(), 'customer_account_id' => CustomerAccount::factory(),
            'customer_operation_id' => CustomerOperation::factory(), 'sequence' => 1, 'type' => 'payment',
            'amount' => '-1.000', 'balance_before' => '0.000', 'balance_after' => '-1.000', 'currency' => 'UYU',
            'created_by' => fn (array $attributes) => CustomerOperation::findOrFail($attributes['customer_operation_id'])->created_by,
            'customer_name' => 'Cliente de prueba', 'actor_name' => 'Responsable de prueba', 'payment_method' => 'cash', 'occurred_at' => now()];
    }
}
