<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use Illuminate\Foundation\Http\FormRequest;

final class ListReportSourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('admin') || $user->can('reports.view'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
