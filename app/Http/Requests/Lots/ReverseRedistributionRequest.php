<?php

namespace App\Http\Requests\Lots;

final class ReverseRedistributionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('redistribute');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'destination_version' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
