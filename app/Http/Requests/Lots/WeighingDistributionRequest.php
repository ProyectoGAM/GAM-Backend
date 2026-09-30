<?php

namespace App\Http\Requests\Lots;

final class WeighingDistributionRequest extends ViewWeighingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['unit' => ['sometimes', 'in:g,kg']];
    }
}
