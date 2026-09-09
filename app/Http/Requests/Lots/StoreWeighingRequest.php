<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;

final class StoreWeighingRequest extends WeighingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Weighing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(false),
            'id' => ['sometimes', 'ulid'],
            'flock_id' => ['required', 'ulid', 'exists:flocks,public_id'],
            'mode' => ['required', 'in:individual,group'],
            'unit' => ['required', 'in:g,kg'],
            'occurred_at' => $this->timeRules(),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'confirm_out_of_range' => ['sometimes', 'boolean'],
            'measurements' => ['required', 'array', 'min:1', 'max:1000'],
            'measurements.*' => ['required', 'array'],
            'measurements.*.weight' => ['required_if:mode,individual', 'string', 'regex:'.$this->decimalPattern(4), 'prohibited_unless:mode,individual'],
            'measurements.*.total_weight' => ['required_if:mode,group', 'string', 'regex:'.$this->decimalPattern(4), 'prohibited_unless:mode,group'],
            'measurements.*.bird_count' => ['required_if:mode,group', 'integer', 'min:1', 'max:2147483647', 'prohibited_unless:mode,group'],
        ];
    }
}
