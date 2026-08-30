<?php

namespace App\Modules\ReportingAndAnalytics\Application\Services;

use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Domain\Data\ReportSourceDefinition;
use App\Modules\ReportingAndAnalytics\Domain\Exceptions\ReportQueryValidationException;
use Illuminate\Support\Carbon;

final readonly class ReportQueryNormalizer
{
    public function __construct(private ReportSourceRegistry $registry) {}

    /**
     * Valida y normaliza únicamente las partes declaradas por la fuente.
     *
     * @param  array<string, mixed>  $input
     */
    public function normalize(string $sourceKey, array $input): ReportQueryData
    {
        $source = $this->registry->get($sourceKey);
        $definition = $source->definition();
        $this->assertAllowedKeys($input);

        $columns = $this->listOfStrings($input['columns'] ?? array_keys($definition->columns), 'columns');
        foreach ($columns as $column) {
            if (! array_key_exists($column, $definition->columns)) {
                $this->invalid('columns', "La columna {$column} no está permitida.");
            }
        }

        $filters = $this->normalizeFilters($input['filters'] ?? [], $definition);
        $from = $this->normalizeDate($input['from'] ?? null, 'from');
        $to = $this->normalizeDate($input['to'] ?? null, 'to');
        $this->assertDateRange($from, $to, $definition);

        $sortInput = $input['sorts'] ?? $input['sort'] ?? [];
        $sorts = $this->normalizeSorts($sortInput, $definition);
        $groupings = $this->normalizeNamedList($input['groupings'] ?? [], $definition->groupings, 'groupings');
        $metrics = $this->normalizeNamedList($input['metrics'] ?? [], $definition->metrics, 'metrics');

        if ($definition->quantityMetricsRequireUnit && $this->hasQuantityMetric($metrics, $definition)
            && ! in_array('base_unit', $groupings, true) && array_key_exists('base_unit', $definition->groupings)) {
            $groupings[] = 'base_unit';
        }

        if ($sorts === []) {
            $sorts = [$this->defaultSort($definition, [...$groupings, ...$metrics])];
        }

        $page = $this->positiveInteger($input['page'] ?? 1, 'page');
        $perPage = $this->positiveInteger($input['per_page'] ?? 50, 'per_page');
        if ($perPage > $definition->limits['max_page_size']) {
            $this->invalid('per_page', 'La cantidad de filas por página supera el límite permitido.');
        }

        return new ReportQueryData(
            sourceKey: $sourceKey,
            definitionVersion: $definition->definitionVersion,
            columns: $columns,
            filters: $filters,
            from: $from,
            to: $to,
            sorts: $sorts,
            groupings: $groupings,
            metrics: $metrics,
            page: $page,
            perPage: $perPage,
        );
    }

    /** @param array<string, mixed> $input */
    private function assertAllowedKeys(array $input): void
    {
        $allowed = ['columns', 'filters', 'from', 'to', 'sorts', 'sort', 'groupings', 'metrics', 'page', 'per_page'];
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            $this->invalid('query', 'La consulta contiene claves no permitidas: '.implode(', ', $unknown).'.');
        }

        if (array_key_exists('sorts', $input) && array_key_exists('sort', $input)) {
            $this->invalid('sorts', 'Usa una sola forma para indicar el orden.');
        }
    }

    /** @return list<array{field: string, operator: string, value: mixed}> */
    private function normalizeFilters(mixed $input, ReportSourceDefinition $definition): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->invalid('filters', 'Los filtros deben ser una lista.');
        }

        $filters = [];
        foreach ($input as $index => $filter) {
            if (! is_array($filter) || ! array_key_exists('field', $filter) || ! array_key_exists('operator', $filter)
                || ! array_key_exists('value', $filter)) {
                $this->invalid("filters.{$index}", 'Cada filtro debe indicar field, operator y value.');
            }

            $unknown = array_diff(array_keys($filter), ['field', 'operator', 'value']);
            if ($unknown !== []) {
                $this->invalid("filters.{$index}", 'Cada filtro solo puede indicar field, operator y value.');
            }

            $field = $filter['field'];
            $operator = $filter['operator'];
            if (! is_string($field) || ! array_key_exists($field, $definition->filters)) {
                $this->invalid("filters.{$index}.field", 'El campo del filtro no está permitido.');
            }
            if (! is_string($operator) || ! in_array($operator, $definition->filters[$field]['operators'], true)) {
                $this->invalid("filters.{$index}.operator", 'El operador del filtro no está permitido.');
            }

            $value = $filter['value'];
            $filterDefinition = $definition->filters[$field];
            if (in_array($operator, ['in', 'not_in'], true)) {
                if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                    $this->invalid("filters.{$index}.value", 'El valor debe ser una lista de hasta 100 elementos.');
                }
                foreach ($value as $item) {
                    $this->assertFilterValue($item, $filterDefinition, "filters.{$index}.value");
                }
                if ($filterDefinition['type'] === 'integer') {
                    $value = array_map(static fn (int|string $item): int => (int) $item, $value);
                }
            } else {
                $this->assertFilterValue($value, $filterDefinition, "filters.{$index}.value");
                if ($filterDefinition['type'] === 'integer') {
                    $value = (int) $value;
                }
            }

            $filters[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return $filters;
    }

    /** @param array{label: string, type: string, operators: list<string>, options?: list<string>} $definition */
    private function assertFilterValue(mixed $value, array $definition, string $key): void
    {
        $valid = match ($definition['type']) {
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'boolean' => is_bool($value),
            'string', 'enum' => is_string($value) && $value !== '' && mb_strlen($value) <= 160,
            default => false,
        };

        if ($valid && $definition['type'] === 'enum' && isset($definition['options'])) {
            $valid = in_array($value, $definition['options'], true);
        }

        if (! $valid) {
            $this->invalid($key, 'El valor del filtro no tiene un tipo válido.');
        }
    }

    /** @return list<array{field: string, direction: string}> */
    private function normalizeSorts(mixed $input, ReportSourceDefinition $definition): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->invalid('sorts', 'Los ordenamientos deben ser una lista.');
        }

        $sorts = [];
        foreach ($input as $index => $sort) {
            if (! is_array($sort) || ! is_string($sort['field'] ?? null)
                || ! in_array($sort['direction'] ?? null, ['asc', 'desc'], true)) {
                $this->invalid("sorts.{$index}", 'Cada ordenamiento debe indicar field y direction válidos.');
            }

            $unknown = array_diff(array_keys($sort), ['field', 'direction']);
            if ($unknown !== []) {
                $this->invalid("sorts.{$index}", 'Cada ordenamiento solo puede indicar field y direction.');
            }

            $field = $sort['field'];
            if (! array_key_exists($field, $definition->sorts)) {
                $this->invalid("sorts.{$index}.field", 'El campo de ordenamiento no está permitido.');
            }
            $sorts[] = ['field' => $field, 'direction' => $sort['direction']];
        }

        return $sorts;
    }

    /** @param array<string, array{label: string, type: string}> $allowed */
    private function normalizeNamedList(mixed $input, array $allowed, string $key): array
    {
        $values = $this->listOfStrings($input, $key);
        if (count($values) > 10) {
            $this->invalid($key, 'No puedes indicar más de 10 elementos.');
        }

        foreach ($values as $value) {
            if (! array_key_exists($value, $allowed)) {
                $this->invalid($key, "El valor {$value} no está permitido.");
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function listOfStrings(mixed $input, string $key): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->invalid($key, 'El valor debe ser una lista.');
        }
        foreach ($input as $value) {
            if (! is_string($value) || $value === '' || mb_strlen($value) > 80) {
                $this->invalid($key, 'La lista contiene un valor inválido.');
            }
        }

        return $input;
    }

    private function normalizeDate(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $this->invalid($key, 'La fecha debe tener formato YYYY-MM-DD.');
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid($key, 'La fecha debe tener formato YYYY-MM-DD.');
        }

        return $value;
    }

    private function assertDateRange(?string $from, ?string $to, ReportSourceDefinition $definition): void
    {
        if ($from === null || $to === null) {
            return;
        }

        $fromDate = Carbon::createFromFormat('!Y-m-d', $from);
        $toDate = Carbon::createFromFormat('!Y-m-d', $to);
        if ($fromDate->greaterThan($toDate)) {
            $this->invalid('to', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if ($fromDate->diffInDays($toDate) > $definition->limits['max_range_days']) {
            $this->invalid('to', 'El rango de fechas supera el límite permitido.');
        }
    }

    private function positiveInteger(mixed $value, string $key): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            $this->invalid($key, 'El valor debe ser un entero positivo.');
        }

        return $value;
    }

    /** @return array{field: string, direction: string} */
    private function defaultSort(ReportSourceDefinition $definition, array $availableFields): array
    {
        [$field, $direction] = array_pad(explode(':', $definition->defaultSort, 2), 2, 'asc');

        if ($availableFields !== [] && ! in_array($field, $availableFields, true)) {
            $field = $availableFields[0];
        }

        return ['field' => $field, 'direction' => $direction];
    }

    /** @param list<string> $metrics */
    private function hasQuantityMetric(array $metrics, ReportSourceDefinition $definition): bool
    {
        foreach ($metrics as $metric) {
            if (($definition->metrics[$metric]['type'] ?? null) === 'quantity') {
                return true;
            }
        }

        return false;
    }

    private function invalid(string $key, string $message): never
    {
        throw new ReportQueryValidationException([$key => [$message]]);
    }
}
