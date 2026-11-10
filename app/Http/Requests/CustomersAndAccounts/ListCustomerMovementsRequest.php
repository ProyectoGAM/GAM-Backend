<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class ListCustomerMovementsRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('account', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return ['from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['sometimes', 'nullable', 'in:sale_charge,payment,adjustment_increase,adjustment_decrease,reversal'],
            'page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
