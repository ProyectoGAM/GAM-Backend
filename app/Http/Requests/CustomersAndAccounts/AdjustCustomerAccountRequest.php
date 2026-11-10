<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class AdjustCustomerAccountRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), 'direction' => ['required', 'in:increase,decrease'], 'amount' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'], 'expected_version' => ['required', 'integer', 'min:0']];
    }
}
