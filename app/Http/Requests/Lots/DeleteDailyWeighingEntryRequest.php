<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class DeleteDailyWeighingEntryRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->commandRules(), 'reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
