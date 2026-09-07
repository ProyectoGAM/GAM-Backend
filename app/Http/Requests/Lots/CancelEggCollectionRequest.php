<?php

namespace App\Http\Requests\Lots;

final class CancelEggCollectionRequest extends LotsRequest
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
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
