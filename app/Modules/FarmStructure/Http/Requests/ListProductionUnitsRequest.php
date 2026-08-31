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
            'buscar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'localidad_id' => ['sometimes', 'integer', 'exists:localities,id'],
            'estado' => ['sometimes', Rule::enum(ProductionUnitStatus::class)],
            'por_pagina' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
