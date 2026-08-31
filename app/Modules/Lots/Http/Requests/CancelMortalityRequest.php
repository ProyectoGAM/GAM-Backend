<?php

namespace App\Modules\Lots\Http\Requests;

final class CancelMortalityRequest extends LotsRequest
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
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
