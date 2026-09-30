<?php

namespace App\Http\Requests\ManagementPlans;

final class AssignFlockPlanRequest extends ManagementPlanRequest
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
            'plan_template_id' => ['required', 'ulid'],
            'plan_template_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
