<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\EggCollection;

final class FlockMetricsRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EggCollection::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'flock_id' => ['sometimes', 'ulid'],
            'poultry_house_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'production_unit_id' => ['sometimes', 'integer', 'exists:production_units,id'],
        ];
    }
}
