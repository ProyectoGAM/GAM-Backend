<?php

namespace App\Http\Requests\ReportingAndAnalytics;

use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListReportOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registry = app(ReportSourceRegistry::class);

        return $this->user() !== null && $registry->canRead($this->user(), $registry->get((string) $this->route('source')));
    }

    public function rules(): array
    {
        $definition = app(ReportSourceRegistry::class)->get((string) $this->route('source'))->definition();

        return ['field' => ['required', Rule::in(array_keys($definition->filters))],
            'search' => ['sometimes', 'string', 'max:160'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']];
    }
}
