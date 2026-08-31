<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Data;

final readonly class ReportSourceDefinition
{
    /**
     * @param  array<string, array{label: string, tipo: string, unit?: string|null}>  $columns
     * @param  array<string, array{label: string, tipo: string, operators: list<string>, options?: list<string>, options_source?: string}>  $filters
     * @param  array<string, array{label: string, tipo: string}>  $groupings
     * @param  array<string, array{label: string, tipo: string, unit?: string|null}>  $metrics
     * @param  array<string, array{label: string, direccion: string}>  $sorts
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
            'version_definicion' => $this->definitionVersion,
            'label' => $this->label,
            'description' => $this->description,
            'columnas' => $this->columns,
            'filtros' => $this->filters,
            'agrupaciones' => $this->groupings,
            'metricas' => $this->metrics,
            'ordenamientos' => $this->sorts,
            'default_sort' => $this->defaultSort,
            'formats' => $this->formats,
            'limits' => $this->limits,
        ];
    }
}
