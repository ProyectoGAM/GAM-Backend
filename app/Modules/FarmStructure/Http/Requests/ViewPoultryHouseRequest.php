<?php

namespace App\Modules\FarmStructure\Http\Requests;

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
