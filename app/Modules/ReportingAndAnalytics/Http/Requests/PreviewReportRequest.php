<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;

final class PreviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registry = app(ReportSourceRegistry::class);
        $source = $registry->get((string) $this->route('source'));

        return $this->user() !== null && $registry->canRead($this->user(), $source);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'columns' => ['sometimes', 'array', 'max:30'],
            'columns.*' => ['string', 'max:80'],
            'filters' => ['sometimes', 'array', 'max:20'],
            'filters.*' => ['array'],
            'filters.*.field' => ['required', 'string', 'max:80'],
            'filters.*.operator' => ['required', 'string', 'max:20'],
            'filters.*.value' => ['present'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sorts' => ['sometimes', 'array', 'max:10'],
            'sorts.*' => ['array'],
            'sorts.*.field' => ['required', 'string', 'max:80'],
            'sorts.*.direction' => ['required', 'in:asc,desc'],
            'sort' => ['sometimes', 'array', 'max:10'],
            'sort.*' => ['array'],
            'sort.*.field' => ['required', 'string', 'max:80'],
            'sort.*.direction' => ['required', 'in:asc,desc'],
            'groupings' => ['sometimes', 'array', 'max:10'],
            'groupings.*' => ['string', 'max:80'],
            'metrics' => ['sometimes', 'array', 'max:10'],
            'metrics.*' => ['string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
