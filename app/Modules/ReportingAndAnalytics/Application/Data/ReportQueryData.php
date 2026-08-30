<?php

namespace App\Modules\ReportingAndAnalytics\Application\Data;

final readonly class ReportQueryData
{
    /**
     * @param  list<string>  $columns
     * @param  list<array{field: string, operator: string, value: mixed}>  $filters
     * @param  list<array{field: string, direction: string}>  $sorts
     * @param  list<string>  $groupings
     * @param  list<string>  $metrics
     */
    public function __construct(
        public string $sourceKey,
        public string $definitionVersion,
        public array $columns,
        public array $filters,
        public ?string $from,
        public ?string $to,
        public array $sorts,
        public array $groupings,
        public array $metrics,
        public int $page,
        public int $perPage,
    ) {}

    /**
     * Devuelve una representación estable para hashes e idempotencia.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clave_fuente' => $this->sourceKey,
            'version_definicion' => $this->definitionVersion,
            'columnas' => $this->columns,
            'filtros' => array_map(
                static fn (array $filter): array => [
                    'campo' => $filter['field'],
                    'operador' => $filter['operator'],
                    'valor' => $filter['value'],
                ],
                $this->filters,
            ),
            'desde' => $this->from,
            'hasta' => $this->to,
            'ordenamientos' => array_map(
                static fn (array $sort): array => [
                    'campo' => $sort['field'],
                    'direccion' => $sort['direction'],
                ],
                $this->sorts,
            ),
            'agrupaciones' => $this->groupings,
            'metricas' => $this->metrics,
            'pagina' => $this->page,
            'por_pagina' => $this->perPage,
        ];
    }
}
