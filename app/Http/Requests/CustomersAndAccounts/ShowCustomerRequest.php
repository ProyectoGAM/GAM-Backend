<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class ShowCustomerRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
