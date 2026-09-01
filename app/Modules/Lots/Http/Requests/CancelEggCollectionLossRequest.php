<?php

namespace App\Modules\Lots\Http\Requests;

final class CancelEggCollectionLossRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recoleccion')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(version: false),
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
