<?php

namespace App\Http\Requests\Lots;

final class UpdateFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'code' => ['sometimes', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
