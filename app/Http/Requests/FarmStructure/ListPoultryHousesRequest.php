<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\PoultryHouseStatus;
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
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(PoultryHouseStatus::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
