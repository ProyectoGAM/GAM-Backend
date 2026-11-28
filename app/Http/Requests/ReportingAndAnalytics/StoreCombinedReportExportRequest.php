<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use App\Enums\ReportingAndAnalytics\ReportExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCombinedReportExportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('admin') || ($user->can('can_view_reports') && $user->can('can_export_reports')));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'format' => ['required', Rule::enum(ReportExportFormat::class)],
            'sections' => ['required', 'array', 'list', 'min:2', 'max:23'],
            'sections.*' => ['required', 'array:source_key,query'],
            'sections.*.source_key' => ['required', 'string', 'max:100', 'distinct:strict'],
            'sections.*.query' => ['present', 'array:columns,filters,from,date_preset,to,sorts,sort,groupings,metrics,page,per_page'],
            ...StoreReportExportRequest::queryRules('sections.*.query.'),
        ];
    }
}
