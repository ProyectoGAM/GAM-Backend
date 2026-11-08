<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ExpenseSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['can_view_poultry_houses', 'can_view_vehicle_fuel', 'can_view_vehicle_maintenance']) ?? false;
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
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $timezone = config('lots.timezone');
            $from = CarbonImmutable::parse($this->string('desde')->toString(), $timezone);
            $to = CarbonImmutable::parse($this->string('hasta')->toString(), $timezone);
            if ($from->diffInDays($to) >= 366) {
                $validator->errors()->add('hasta', 'El período de gastos puede comprender hasta 366 días.');
            }
        }];
    }
}
