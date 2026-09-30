<?php

namespace App\Http\Requests\FarmStructure;

final class ViewPoultryHouseRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizePoultryHouse('view');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
