<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Services\CustomersAndAccounts\CustomerFields;

final class SaveCustomerRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), ...CustomerFields::rules()];
    }
}
