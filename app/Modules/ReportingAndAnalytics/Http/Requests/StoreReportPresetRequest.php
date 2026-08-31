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
            'nombre' => ['required', 'string', 'max:160'],
            'clave_fuente' => ['required', 'string', 'max:100'],
            'configuracion' => ['required', 'array'],
        ];
    }
}
