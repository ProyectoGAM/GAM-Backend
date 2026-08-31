<?php

namespace App\Modules\Lots\Http\Requests;

final class CorrectMortalityRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('mortalidad')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'version_lote' => ['required', 'integer', 'min:1'],
            'cantidad' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'categoria_mortalidad_id' => ['sometimes', 'integer', 'min:1'],
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
