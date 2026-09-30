<?php

namespace App\Http\Requests\FarmStructure;

final class ViewProductionUnitRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizeProductionUnit('view');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
