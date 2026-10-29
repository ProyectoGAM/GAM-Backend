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
            'plan_activity_id' => ['nullable', 'ulid'],
            'occurred_at' => ['required', ...array_slice($this->timeRules(), 1)],
            'notes' => ['required', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(),
            'occurred_at.required' => 'Debes indicar la fecha de la mortalidad.',
            'notes.required' => 'Debes indicar el motivo de la mortalidad.',
            'notes.string' => 'El motivo de la mortalidad debe ser un texto.',
        ];
    }
}
