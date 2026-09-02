<?php

namespace App\Modules\Lots\Http\Requests;

final class CorrectEggCollectionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recoleccion')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'cantidad' => ['sometimes', ...$this->quantityRules()],
            'ocurrido_en' => $this->timeRules(),
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'motivo_correccion' => ['required', 'string', 'max:500'],
        ];
    }
}
