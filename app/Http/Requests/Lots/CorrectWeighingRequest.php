<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\Weighing;
use Illuminate\Validation\Validator;

final class CorrectWeighingRequest extends WeighingsRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $weighing = $this->route('pesaje');
        if (! $weighing instanceof Weighing) {
            return;
        }

        $this->merge([
            'mode' => $this->input('mode', $weighing->mode),
            'unit' => $this->input('unit', $weighing->captured_unit),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('pesaje')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'flock_id' => ['sometimes', 'ulid', 'exists:flocks,public_id'],
            'mode' => ['sometimes', 'in:individual,group'],
            'unit' => ['sometimes', 'in:g,kg'],
            'occurred_at' => $this->timeRules(),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'correction_reason' => ['required', 'string', 'max:500'],
            'confirm_out_of_range' => ['sometimes', 'boolean'],
            'measurements' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'measurements.*' => ['required', 'array'],
            'measurements.*.weight' => ['required_if:mode,individual', 'string', 'regex:'.$this->decimalPattern(4), 'prohibited_unless:mode,individual'],
            'measurements.*.total_weight' => ['required_if:mode,group', 'string', 'regex:'.$this->decimalPattern(4), 'prohibited_unless:mode,group'],
            'measurements.*.bird_count' => ['required_if:mode,group', 'integer', 'min:1', 'max:2147483647', 'prohibited_unless:mode,group'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $weighing = $this->route('pesaje');
                if ($weighing instanceof Weighing
                    && $this->input('mode') !== $weighing->mode
                    && ! array_key_exists('measurements', $this->all())) {
                    $validator->errors()->add('measurements', 'Debes enviar las mediciones completas al cambiar el modo de pesaje.');
                }
            },
        ];
    }
}
