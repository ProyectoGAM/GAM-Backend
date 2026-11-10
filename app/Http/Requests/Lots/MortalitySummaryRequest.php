<?php

namespace App\Http\Requests\Lots;

use App\Models\Lots\MortalityRecord;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

final class MortalitySummaryRequest extends LotsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MortalityRecord::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'up_id' => ['sometimes', 'integer', 'min:1', 'exists:production_units,id'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = CarbonImmutable::parse($this->string('desde')->toString(), config('lots.timezone'));
            $to = CarbonImmutable::parse($this->string('hasta')->toString(), config('lots.timezone'));
            if ($from->diffInDays($to) >= 366) {
                $validator->errors()->add('hasta', 'El período de mortalidad puede comprender hasta 366 días.');
            }
        }];
    }
}
