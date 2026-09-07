<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\EggCollection;

final class StoreEggCollectionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EggCollection::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(false),
            'id' => ['sometimes', 'ulid'],
            'quantity' => $this->quantityRules(),
            'occurred_at' => $this->timeRules(),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
