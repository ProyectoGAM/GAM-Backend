<?php

namespace App\Modules\Lots\Http\Requests;

use App\Models\Lots\MortalityCategory;

final class SaveMortalityCategoryRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST') ? ($this->user()?->can('create', MortalityCategory::class) ?? false) : ($this->user()?->can('update', $this->route('categoria')) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(! $this->isMethod('POST')),
            'nombre' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'estado' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
