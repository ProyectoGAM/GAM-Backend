<?php

namespace App\Http\Requests\Lots;

final class CorrectMortalityRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('mortality')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'flock_version' => ['required', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'occurred_at' => $this->timeRules(),
            'notes' => ['sometimes', 'required', 'string', 'max:5000'],
            'confirm_reactivation' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(),
            'notes.required' => 'Debes indicar el motivo de la mortalidad.',
            'notes.string' => 'El motivo de la mortalidad debe ser un texto.',
        ];
    }
}
