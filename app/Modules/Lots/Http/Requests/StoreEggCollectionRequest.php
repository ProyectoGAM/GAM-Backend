<?php

namespace App\Modules\Lots\Http\Requests;

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
            'cantidad' => $this->quantityRules(),
            'ocurrido_en' => $this->timeRules(),
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
