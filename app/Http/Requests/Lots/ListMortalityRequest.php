<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\MortalityRecord;

final class ListMortalityRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MortalityRecord::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'flock_id' => ['sometimes', 'ulid'],
            'poultry_house_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'status' => ['sometimes', 'in:recorded,cancelled'],
        ];
    }
}
