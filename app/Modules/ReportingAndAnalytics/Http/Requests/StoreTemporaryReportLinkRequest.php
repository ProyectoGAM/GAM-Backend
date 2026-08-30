<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTemporaryReportLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('share', $this->route('reportExport')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['expires_in' => $this->input('expires_in', 30)]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['expires_in' => ['required', 'integer', 'min:1', 'max:'.(int) config('reporting.shared_link_max_minutes', 60)]];
    }
}
