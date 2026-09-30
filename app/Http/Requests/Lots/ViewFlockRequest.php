<?php

namespace App\Http\Requests\Lots;

final class ViewFlockRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->flockAbility('view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [

        ];
    }
}
