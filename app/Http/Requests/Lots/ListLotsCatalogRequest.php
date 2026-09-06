<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Breed;
use App\Models\Lots\MortalityCategory;

final class ListLotsCatalogRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', str_contains($this->route()->getName(), '.breeds.') ? Breed::class : MortalityCategory::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buscar' => ['sometimes', 'string', 'max:120'],
            'estado' => ['sometimes', 'in:active,inactive'],
            'pagina' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
