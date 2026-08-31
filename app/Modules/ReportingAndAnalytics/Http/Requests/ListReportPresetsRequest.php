<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListReportPresetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('admin') || $user->can('reports.presets.manage'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
