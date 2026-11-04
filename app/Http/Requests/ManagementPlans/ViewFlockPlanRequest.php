<?php

namespace App\Http\Requests\ManagementPlans;

final class ViewFlockPlanRequest extends ManagementPlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
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
            'include_revisions' => ['sometimes', 'boolean'],
        ];
    }
}
