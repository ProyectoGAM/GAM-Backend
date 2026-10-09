<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class DailyWeighingDistributionRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
