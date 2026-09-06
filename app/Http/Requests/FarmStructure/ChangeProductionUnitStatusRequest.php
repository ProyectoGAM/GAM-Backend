<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmStructure\ProductionUnitStatus;
use Illuminate\Validation\Rule;

final class ChangeProductionUnitStatusRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizeProductionUnit('changeStatus');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(ProductionUnitStatus::class)],
        ];
    }
}
