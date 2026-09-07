<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Breed;

final class SaveBreedRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST') ? ($this->user()?->can('create', Breed::class) ?? false) : ($this->user()?->can('update', $this->route('breed')) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(! $this->isMethod('POST')),
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
