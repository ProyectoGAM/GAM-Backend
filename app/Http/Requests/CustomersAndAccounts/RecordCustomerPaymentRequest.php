<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class RecordCustomerPaymentRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pay', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [...$this->commandRules(), 'amount' => ['required', 'string'], 'payment_method' => ['required', 'in:cash,transfer,other'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
