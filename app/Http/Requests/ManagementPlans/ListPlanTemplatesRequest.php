<?php

namespace App\Http\Requests\ManagementPlans;

final class ListPlanTemplatesRequest extends ManagementPlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (array_key_exists('has_draft', $this->all())) {
            return $this->allowed('can_manage_management_plans');
        }

        return $this->allowed('can_view_management_plans') || $this->allowed('can_manage_management_plans');
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
            'status' => ['sometimes', 'in:active,retired'],
            'has_draft' => ['sometimes', 'in:1'],
        ];
    }
}
