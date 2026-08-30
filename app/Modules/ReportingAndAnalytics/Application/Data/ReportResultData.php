<?php

namespace App\Modules\ReportingAndAnalytics\Application\Data;

use Illuminate\Support\Carbon;

final readonly class ReportResultData
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $aggregates
     * @param  array<string, string|null>  $units
     */
    public function __construct(
        public string $sourceKey,
        public string $definitionVersion,
        public array $columns,
        public array $rows,
        public array $aggregates,
        public array $units,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public Carbon $generatedAt,
    ) {}

    /**
     * Convierte el resultado al contrato estable usado por preview, widgets y exportaciones.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clave_fuente' => $this->sourceKey,
            'version_definicion' => $this->definitionVersion,
            'columnas' => $this->columns,
            'rows' => $this->rows,
            'aggregates' => $this->aggregates,
            'units' => $this->units,
            'pagination' => [
                'current_page' => $this->currentPage,
                'por_pagina' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
            ],
            'generated_at' => $this->generatedAt,
        ];
    }
}
