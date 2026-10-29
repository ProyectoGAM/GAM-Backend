<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use Illuminate\Foundation\Http\FormRequest;

final class DownloadReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->boolean('share') && $this->hasValidSignature()) {
            return true;
        }

        return ($this->user() ?? auth('sanctum')->user())?->can('download', $this->route('reportExport')) ?? false;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'view' => ['sometimes', 'in:html']];
    }
}
