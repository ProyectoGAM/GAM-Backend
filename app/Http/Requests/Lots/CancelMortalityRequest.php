<?php

namespace App\Http\Requests\Lots;

final class CancelMortalityRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('mortality')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'flock_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
