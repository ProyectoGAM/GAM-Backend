<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\WeighingReferenceSettings;

final class ViewWeighingSettingsRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', WeighingReferenceSettings::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
