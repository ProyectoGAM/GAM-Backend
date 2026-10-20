<?php

namespace App\Http\Requests\Inventory;

final class UpdateEggPresentationRequest extends StoreEggPresentationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'eggs_per_unit' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'default_unit_price' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
