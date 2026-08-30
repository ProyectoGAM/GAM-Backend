<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateReportPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('reportPreset')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'source_key' => ['sometimes', 'string', 'max:100'],
            'configuration' => ['sometimes', 'array'],
        ];
    }
}
