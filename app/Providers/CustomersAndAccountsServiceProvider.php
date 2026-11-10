<?php

namespace App\Providers;

use App\Models\CustomersAndAccounts\Customer;
use App\Policies\CustomersAndAccounts\CustomerPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class CustomersAndAccountsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
