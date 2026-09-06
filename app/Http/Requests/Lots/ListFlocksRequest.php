<?php

namespace App\Http\Requests\Lots;

final class ListFlocksRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('viewAny');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'search' => ['sometimes', 'string', 'max:120'],
            'poultry_house_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'production_unit_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'breed_id' => ['sometimes', 'integer', 'exists:breeds,id'],
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'status' => ['sometimes', 'in:active,quarantined,finished'],
        ];
    }
}
