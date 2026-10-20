<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Breed;

final class ListLotsCatalogRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Breed::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
