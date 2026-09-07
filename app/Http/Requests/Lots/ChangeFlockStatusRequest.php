<?php

namespace App\Http\Requests\Lots;

final class ChangeFlockStatusRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('changeStatus');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'status' => ['required', 'in:active,quarantined'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
