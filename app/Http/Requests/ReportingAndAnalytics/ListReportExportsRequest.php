<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use App\Enums\ReportingAndAnalytics\ReportExportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListReportExportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('admin') || $user->can('reports.view'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ReportExportStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
