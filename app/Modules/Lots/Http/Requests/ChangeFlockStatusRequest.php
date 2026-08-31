<?php

namespace App\Modules\Lots\Http\Requests;

final class ChangeFlockStatusRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('changeStatus');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'estado' => ['required', 'in:active,quarantined'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
