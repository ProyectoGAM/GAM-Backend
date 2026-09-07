<?php

namespace App\Http\Requests\Lots;

use Illuminate\Validation\Rule;

final class RedistributeFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('redistribute');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'quantity' => $this->quantityRules(),
            'destination_poultry_house_id' => ['required_without:destination_flock_id', 'prohibits:destination_flock_id', 'integer', 'min:1'],
            'destination_flock_id' => ['required_without:destination_poultry_house_id', 'prohibits:destination_poultry_house_id', 'ulid'],
            'destination_version' => ['required_with:destination_flock_id', Rule::prohibitedIf(! $this->filled('destination_flock_id')), 'integer', 'min:1'],
            'destination_code' => ['sometimes', 'prohibits:destination_flock_id', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'destination_public_id' => ['sometimes', 'prohibits:destination_flock_id', 'ulid'],
            'occurred_at' => $this->timeRules(),
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
