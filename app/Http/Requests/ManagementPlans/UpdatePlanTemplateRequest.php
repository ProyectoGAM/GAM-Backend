<?php

namespace App\Http\Requests\ManagementPlans;

final class UpdatePlanTemplateRequest extends ManagementPlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->allowed('management-plans.manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            ...$this->activityRules(),
        ];
    }
}
