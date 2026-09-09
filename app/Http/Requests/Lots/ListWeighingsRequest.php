<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class ListWeighingsRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->filterRules(), 'flock_id' => ['sometimes', 'ulid'], 'mode' => ['sometimes', 'in:individual,group']];
    }
}
