<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\MortalityRecord;

final class StoreMortalityRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MortalityRecord::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'id' => ['sometimes', 'ulid'],
            'quantity' => $this->quantityRules(),
            'mortality_category_id' => ['required', 'integer', 'min:1'],
            'occurred_at' => $this->timeRules(),
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
