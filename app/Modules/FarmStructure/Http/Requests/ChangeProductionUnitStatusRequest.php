<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
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
