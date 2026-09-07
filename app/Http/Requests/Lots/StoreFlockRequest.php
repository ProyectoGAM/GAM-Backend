<?php

namespace App\Http\Requests\Lots;

final class StoreFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(false),
            'id' => ['sometimes', 'ulid'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'breed_id' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1', 'required_without:origin'],
            'origin' => ['nullable', 'string', 'max:255', 'required_without:supplier_id'],
            'poultry_house_id' => ['required', 'integer', 'min:1'],
            'initial_quantity' => $this->quantityRules(),
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
