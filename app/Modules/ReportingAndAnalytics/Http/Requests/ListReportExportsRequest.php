<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
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
            'estado' => ['sometimes', Rule::enum(ReportExportStatus::class)],
            'pagina' => ['sometimes', 'integer', 'min:1'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
