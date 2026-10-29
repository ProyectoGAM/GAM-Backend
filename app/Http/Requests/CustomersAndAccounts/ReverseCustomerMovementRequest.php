<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class ReverseCustomerMovementRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reverse', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), 'reason' => ['required', 'string', 'max:2000']];
    }
}
