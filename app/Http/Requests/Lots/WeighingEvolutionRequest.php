<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class WeighingEvolutionRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'flock_id' => ['required', 'ulid', 'exists:flocks,public_id'],
            'mode' => ['sometimes', 'in:individual,group'],
            'unit' => ['sometimes', 'in:g,kg'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ];
    }
}
