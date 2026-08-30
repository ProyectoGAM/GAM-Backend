<?php

namespace App\Modules\FarmStructure\Http\Requests;

use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use Illuminate\Validation\Rule;

final class ChangePoultryHouseStatusRequest extends FarmStructureRequest
{
    public function authorize(): bool
    {
        return $this->authorizePoultryHouse('changeStatus');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(PoultryHouseStatus::class)],
        ];
    }
}
