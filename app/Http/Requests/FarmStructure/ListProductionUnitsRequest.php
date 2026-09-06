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
            'buscar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'localidad_id' => ['sometimes', 'integer', 'exists:localities,id'],
            'estado' => ['sometimes', Rule::enum(ProductionUnitStatus::class)],
            'por_pagina' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
