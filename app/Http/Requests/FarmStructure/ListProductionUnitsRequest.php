<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Validation\Rule;

final class ListProductionUnitsRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ProductionUnit::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'locality_id' => ['sometimes', 'integer', 'exists:localities,id'],
            'status' => ['sometimes', Rule::enum(ProductionUnitStatus::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
