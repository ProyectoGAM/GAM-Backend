<?php

namespace App\Modules\Lots\Http\Requests;

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
            'tipo' => ['sometimes', 'in:admission,partial_new,partial_existing,total,departure,mortality,mortality_correction,redistribution_reversal'],
        ];
    }
}
