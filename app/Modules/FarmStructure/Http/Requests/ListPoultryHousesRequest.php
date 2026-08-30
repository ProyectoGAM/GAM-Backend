<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use Illuminate\Validation\Rule;

final class ListPoultryHousesRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizePoultryHouseCollection('viewAny');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'estado' => ['sometimes', Rule::enum(PoultryHouseStatus::class)],
            'por_pagina' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
