<?php

namespace App\Modules\ReportingAndAnalytics\Http\Requests;

use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReportExportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        $registry = app(ReportSourceRegistry::class);
        $source = $registry->get((string) $this->route('source'));

        return $this->user() !== null && $registry->canExport($this->user(), $source);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'formato' => ['required', Rule::enum(ReportExportFormat::class)],
            'columnas' => ['sometimes', 'array', 'max:30'],
            'columnas.*' => ['string', 'max:80'],
            'filtros' => ['sometimes', 'array', 'max:20'],
            'filtros.*' => ['array'],
            'filtros.*.campo' => ['required', 'string', 'max:80'],
            'filtros.*.operador' => ['required', 'string', 'max:20'],
            'filtros.*.valor' => ['present'],
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'ordenamientos' => ['sometimes', 'array', 'max:10'],
            'ordenamientos.*' => ['array'],
            'ordenamientos.*.campo' => ['required', 'string', 'max:80'],
            'ordenamientos.*.direccion' => ['required', 'in:asc,desc'],
            'orden' => ['sometimes', 'array', 'max:10'],
            'orden.*' => ['array'],
            'orden.*.campo' => ['required', 'string', 'max:80'],
            'orden.*.direccion' => ['required', 'in:asc,desc'],
            'agrupaciones' => ['sometimes', 'array', 'max:10'],
            'agrupaciones.*' => ['string', 'max:80'],
            'metricas' => ['sometimes', 'array', 'max:10'],
            'metricas.*' => ['string', 'max:80'],
            'pagina' => ['sometimes', 'integer', 'min:1'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
