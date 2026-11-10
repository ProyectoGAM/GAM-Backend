<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;

final class ListCustomersRequest extends CustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive'],
            'account_status' => ['sometimes', 'nullable', 'in:debt,settled,credit'],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }

    public function after(): array
    {
        return [...parent::after(), function ($validator): void {
            if ($this->filled('account_status') && ! $this->user()?->can('account', Customer::class)) {
                $validator->errors()->add('account_status', 'No tenés permiso para filtrar cuentas corrientes.');
            }
        }];
    }
}
