<?php

namespace App\Http\Requests\Lots;

final class CorrectEggCollectionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('collection')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'quantity' => ['sometimes', ...$this->quantityRules()],
            'occurred_at' => $this->timeRules(),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
