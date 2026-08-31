<?php

namespace App\Modules\Lots\Http\Requests;

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
            'version_destino' => ['sometimes', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
