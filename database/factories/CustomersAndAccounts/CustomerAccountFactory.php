<?php

namespace Database\Factories\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAccount> */
class CustomerAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'balance' => '0.000', 'version' => 0];
    }
}
