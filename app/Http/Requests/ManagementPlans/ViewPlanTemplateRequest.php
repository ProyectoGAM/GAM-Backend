<?php

namespace App\Http\Requests\ManagementPlans;

use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateVersion;

final class ViewPlanTemplateRequest extends ManagementPlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->allowed('management-plans.manage')) {
            return true;
        }
        if (! $this->allowed('management-plans.view')) {
            return false;
        }

        $template = $this->route('planTemplate');
        if (! $template instanceof PlanTemplate) {
            return false;
        }

        $version = $this->all()['version'] ?? null;
        if ($version === null) {
            return $template->published_version !== null;
        }
        if ((! is_string($version) && ! is_int($version)) || filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return true;
        }

        return ! PlanTemplateVersion::query()
            ->where('plan_template_id', $template->id)
            ->where('number', (int) $version)
            ->whereNull('published_at')
            ->exists();
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
