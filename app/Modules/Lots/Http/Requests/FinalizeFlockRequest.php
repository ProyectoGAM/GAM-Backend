<?php

namespace App\Modules\Lots\Http\Requests;

final class FinalizeFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('finalize');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
