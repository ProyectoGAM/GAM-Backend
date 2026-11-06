<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class ConfirmWeighingAnomalyRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Weighing::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->commandRules();
    }
}
