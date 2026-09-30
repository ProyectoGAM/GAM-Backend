<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\EggCollection;

final class ListEggCollectionsRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EggCollection::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'flock_id' => ['sometimes', 'ulid'],
            'poultry_house_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'production_unit_id' => ['sometimes', 'integer', 'exists:production_units,id'],
            'status' => ['sometimes', 'in:recorded,cancelled'],
        ];
    }
}
