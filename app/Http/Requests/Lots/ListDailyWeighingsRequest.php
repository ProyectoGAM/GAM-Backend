<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class ListDailyWeighingsRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['flock_id' => ['required', 'ulid', 'exists:flocks,public_id'], 'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])], 'cursor' => ['sometimes', 'string', 'max:2000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
