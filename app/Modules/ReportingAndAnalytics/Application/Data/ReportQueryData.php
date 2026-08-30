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
            'source_key' => $this->sourceKey,
            'definition_version' => $this->definitionVersion,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'from' => $this->from,
            'to' => $this->to,
            'sorts' => $this->sorts,
            'groupings' => $this->groupings,
            'metrics' => $this->metrics,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
    }
}
