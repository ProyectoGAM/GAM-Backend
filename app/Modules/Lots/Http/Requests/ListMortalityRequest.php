<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\MortalityRecord;

final class ListMortalityRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MortalityRecord::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'lote_id' => ['sometimes', 'ulid'],
            'galpon_id' => ['sometimes', 'integer', 'exists:poultry_houses,id'],
            'estado' => ['sometimes', 'in:recorded,cancelled'],
        ];
    }
}
