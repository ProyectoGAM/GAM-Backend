<?php

namespace App\Http\Requests\ManagementPlans;

final class ListFlockManagementHistoryRequest extends ManagementPlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->allowed('management-plans.view');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'type' => ['sometimes', 'in:flock_movement,admission,partial_new,partial_existing,total,total_existing,departure,mortality,mortality_correction,redistribution_reversal,weighing,egg_collection,vaccination,medication,ration_change,manual_practice,management_correction'],
        ];
    }
}
