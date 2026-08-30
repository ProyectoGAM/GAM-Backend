<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ViewReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('reportExport')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
