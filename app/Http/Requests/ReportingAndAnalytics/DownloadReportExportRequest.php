<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;

final class DownloadReportExportRequest extends FormRequest
{
    public function authorize(ReportSourceRegistry $registry): bool
    {
        if ($this->boolean('share') && $this->hasValidSignature()) {
            $export = $this->route('reportExport');

            return $export instanceof ReportExport
                && ! $export->isCombined()
                && $registry->get($export->source_key)->definition()->supportsPublicLinks;
        }

        return ($this->user() ?? auth('sanctum')->user())?->can('download', $this->route('reportExport')) ?? false;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'view' => ['sometimes', 'in:html']];
    }
}
