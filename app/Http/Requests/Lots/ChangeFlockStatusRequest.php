<?php

namespace App\Http\Requests\Lots;

final class ChangeFlockStatusRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('changeStatus')
            && (! $this->has('audit_entry_id') || $this->user()?->can('can_view_audit'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'status' => ['required', 'in:active,quarantined'],
            'reason' => ['required', 'string', 'max:500'],
            'audit_entry_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
