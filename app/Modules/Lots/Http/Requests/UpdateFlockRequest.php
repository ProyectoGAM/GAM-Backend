<?php

namespace App\Modules\Lots\Http\Requests;

final class UpdateFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'codigo' => ['sometimes', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'observaciones' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
