<?php

namespace App\Modules\Lots\Http\Requests;

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
            'cantidad' => $this->quantityRules(),
            'categoria_mortalidad_id' => ['required', 'integer', 'min:1'],
            'ocurrido_en' => $this->timeRules(),
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
