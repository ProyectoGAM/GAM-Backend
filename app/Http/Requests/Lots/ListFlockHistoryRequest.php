<?php

namespace App\Http\Requests\Lots;

final class ListFlockHistoryRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'type' => ['sometimes', 'in:admission,partial_new,partial_existing,total,departure,mortality,mortality_correction,redistribution_reversal'],
        ];
    }
}
