<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

class ViewDailyWeighingRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cursor' => ['sometimes', 'string', 'max:2000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
