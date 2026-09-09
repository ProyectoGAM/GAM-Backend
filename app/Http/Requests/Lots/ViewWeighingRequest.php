<?php

namespace App\Http\Requests\Lots;

class ViewWeighingRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('pesaje')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
