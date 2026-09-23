<?php

namespace App\Http\Requests\ManagementPlans;

final class ViewPlanTemplateRequest extends ManagementPlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->allowed('management-plans.view') || $this->allowed('management-plans.manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
