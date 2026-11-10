<?php

namespace Database\Factories\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return ['public_id' => (string) Str::ulid(), 'name' => fake()->company(), 'status' => 'active',
            'address' => 'Av. Artigas 1200', 'latitude' => '-34.7301000', 'longitude' => '-56.2181000',
            'created_by' => User::factory()];
    }

    public function withAccount(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            $account = new CustomerAccount;
            $account->forceFill(['customer_id' => $customer->id, 'balance' => '0.000', 'version' => 0])->save();
        });
    }
}
