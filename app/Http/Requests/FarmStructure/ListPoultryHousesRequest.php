<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\FarmStructure\PoultryHouseType;
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
            'type' => ['sometimes', Rule::enum(PoultryHouseType::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.enum' => 'El tipo de galpón no es válido.',
        ];
    }
}
