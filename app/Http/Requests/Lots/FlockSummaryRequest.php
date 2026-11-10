<?php

namespace App\Http\Requests\Lots;

final class FlockSummaryRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('viewAny');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['up_id' => ['sometimes', 'integer', 'min:1', 'exists:production_units,id']];
    }
}
