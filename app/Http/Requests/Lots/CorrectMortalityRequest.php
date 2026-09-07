<?php

namespace App\Http\Requests\Lots;

final class CorrectMortalityRequest extends LotsRequest
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
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'mortality_category_id' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
