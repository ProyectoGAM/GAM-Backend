<?php

namespace App\Http\Requests\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Services\FarmStructure\UruguayBoundary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ResolveCustomerLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Customer::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys(array_diff_key($this->all(), $validator->getRules())) as $field) {
                $validator->errors()->add($field, 'El campo no está permitido en esta operación.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! app(UruguayBoundary::class)->contains($this->float('latitude'), $this->float('longitude'))) {
                $validator->errors()->add('latitude', 'La ubicación debe encontrarse dentro de Uruguay.');
            }
        }];
    }
}
