<?php

namespace App\DTO\ReportingAndAnalytics;

final readonly class ReportSourceDefinition
{
    /**
     * @param  array<string, array{label: string, type: string, unit?: string|null}>  $columns
     * @param  array<string, array{label: string, type: string, operators: list<string>, options?: list<string>, options_source?: string}>  $filters
     * @param  array<string, array{label: string, type: string}>  $groupings
     * @param  array<string, array{label: string, type: string, unit?: string|null}>  $metrics
     * @param  array<string, array{label: string, direction: string}>  $sorts
     * @param  list<string>  $formats
     * @param  array{max_page_size: int, max_range_days: int, max_export_rows: int}  $limits
     */
    public function __construct(
        public string $key,
        public string $definitionVersion,
        public string $label,
        public string $description,
        public string $permission,
        public array $columns,
        public array $filters,
        public array $groupings,
        public array $metrics,
        public array $sorts,
        public array $formats,
        public array $limits,
        public string $defaultSort,
        public bool $quantityMetricsRequireUnit = true,
        public array $defaultColumns = [],
        public array $standardQueries = [],
        public bool $supportsDateRange = true,
        public array $additionalPermissions = [],
    ) {}

    /**
     * Convierte la definición interna al contrato público sin exponer consultas ni tablas.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'definition_version' => $this->definitionVersion,
            'label' => $this->label,
            'description' => $this->description,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'groupings' => $this->groupings,
            'metrics' => $this->metrics,
            'sorts' => $this->sorts,
            'default_sort' => $this->defaultSort,
            'formats' => $this->formats,
            'limits' => $this->limits,
            'format_limits' => ['xlsx' => $this->limits['max_export_rows'], 'pdf' => min($this->limits['max_export_rows'], (int) config('reporting.pdf_max_rows', 2000))],
            'default_columns' => $this->defaultColumns ?: array_keys($this->columns),
            'standard_queries' => $this->standardQueries,
            'supports_date_range' => $this->supportsDateRange,
        ];
    }
}
