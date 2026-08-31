<?php

namespace App\Modules\Lots\Http\Requests;

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
