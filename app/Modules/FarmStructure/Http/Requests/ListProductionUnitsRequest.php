<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Models\FarmStructure\ProductionUnit;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
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
