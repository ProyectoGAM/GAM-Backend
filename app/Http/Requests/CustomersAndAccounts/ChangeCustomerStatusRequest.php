<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class ChangeCustomerStatusRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), 'status' => ['required', 'in:active,inactive']];
    }
}
