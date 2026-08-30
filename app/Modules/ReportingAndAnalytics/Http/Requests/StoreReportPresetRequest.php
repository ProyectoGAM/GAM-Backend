<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use App\Models\ReportingAndAnalytics\ReportPreset;
use Illuminate\Foundation\Http\FormRequest;

final class StoreReportPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ReportPreset::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'source_key' => ['required', 'string', 'max:100'],
            'configuration' => ['required', 'array'],
        ];
    }
}
