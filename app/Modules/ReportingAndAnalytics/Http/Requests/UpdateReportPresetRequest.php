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
            'nombre' => ['sometimes', 'string', 'max:160'],
            'clave_fuente' => ['sometimes', 'string', 'max:100'],
            'configuracion' => ['sometimes', 'array'],
        ];
    }
}
