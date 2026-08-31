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

        $columns = $this->listOfStrings($input['columnas'] ?? array_keys($definition->columns), 'columnas');
        foreach ($columns as $column) {
            if (! array_key_exists($column, $definition->columns)) {
                $this->invalid('columnas', "La columna {$column} no está permitida.");
            }
        }

        $filters = $this->normalizeFilters($input['filtros'] ?? [], $definition);
        $from = $this->normalizeDate($input['desde'] ?? null, 'desde');
        $to = $this->normalizeDate($input['hasta'] ?? null, 'hasta');
        $this->assertDateRange($from, $to, $definition);

        $sortInput = $input['ordenamientos'] ?? $input['orden'] ?? [];
        $sorts = $this->normalizeSorts($sortInput, $definition);
        $groupings = $this->normalizeNamedList($input['agrupaciones'] ?? [], $definition->groupings, 'agrupaciones');
        $metrics = $this->normalizeNamedList($input['metricas'] ?? [], $definition->metrics, 'metricas');

        // Una agrupación sin métrica solo devuelve etiquetas; agrega la medida principal publicada.
        if ($groupings !== [] && $metrics === [] && $definition->metrics !== []) {
            $metrics = [$this->defaultMetric($definition)];
        }

        if ($definition->quantityMetricsRequireUnit && $this->hasQuantityMetric($metrics, $definition)
            && ! in_array('unidad_base', $groupings, true) && array_key_exists('unidad_base', $definition->groupings)) {
            $groupings[] = 'unidad_base';
        }

        if ($sorts === []) {
            $sorts = [$this->defaultSort($definition, [...$groupings, ...$metrics])];
        }

        $page = $this->positiveInteger($input['pagina'] ?? 1, 'pagina');
        $perPage = $this->positiveInteger($input['por_pagina'] ?? 50, 'por_pagina');
        if ($perPage > $definition->limits['max_page_size']) {
            $this->invalid('por_pagina', 'La cantidad de filas por página supera el límite permitido.');
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
        $allowed = ['columnas', 'filtros', 'desde', 'hasta', 'ordenamientos', 'orden', 'agrupaciones', 'metricas', 'pagina', 'por_pagina'];
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            $this->invalid('consulta', 'La consulta contiene claves no permitidas: '.implode(', ', $unknown).'.');
        }

        if (array_key_exists('ordenamientos', $input) && array_key_exists('orden', $input)) {
            $this->invalid('ordenamientos', 'Usa una sola forma para indicar el orden.');
        }
    }

    /** @return list<array{field: string, operator: string, value: mixed}> */
    private function normalizeFilters(mixed $input, ReportSourceDefinition $definition): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->invalid('filtros', 'Los filtros deben ser una lista.');
        }

        $filters = [];
        foreach ($input as $index => $filter) {
            if (! is_array($filter) || ! array_key_exists('campo', $filter) || ! array_key_exists('operador', $filter)
                || ! array_key_exists('valor', $filter)) {
                $this->invalid("filtros.{$index}", 'Cada filtro debe indicar campo, operador y valor.');
            }

            $unknown = array_diff(array_keys($filter), ['campo', 'operador', 'valor']);
            if ($unknown !== []) {
                $this->invalid("filtros.{$index}", 'Cada filtro solo puede indicar campo, operador y valor.');
            }

            $field = $filter['campo'];
            $operator = $filter['operador'];
            if (! is_string($field) || ! array_key_exists($field, $definition->filters)) {
                $this->invalid("filtros.{$index}.campo", 'El campo del filtro no está permitido.');
            }
            if (! is_string($operator) || ! in_array($operator, $definition->filters[$field]['operators'], true)) {
                $this->invalid("filtros.{$index}.operador", 'El operador del filtro no está permitido.');
            }

            $value = $filter['valor'];
            $filterDefinition = $definition->filters[$field];
            if (in_array($operator, ['in', 'not_in'], true)) {
                if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                    $this->invalid("filtros.{$index}.valor", 'El valor debe ser una lista de hasta 100 elementos.');
                }
                foreach ($value as $item) {
                    $this->assertFilterValue($item, $filterDefinition, "filtros.{$index}.valor");
                }
                if ($filterDefinition['tipo'] === 'integer') {
                    $value = array_map(static fn (int|string $item): int => (int) $item, $value);
                }
            } else {
                $this->assertFilterValue($value, $filterDefinition, "filtros.{$index}.valor");
                if ($filterDefinition['tipo'] === 'integer') {
                    $value = (int) $value;
                }
            }

            $filters[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return $filters;
    }

    /** @param array{label: string, tipo: string, operators: list<string>, options?: list<string>, options_source?: string} $definition */
    private function assertFilterValue(mixed $value, array $definition, string $key): void
    {
        $valid = match ($definition['tipo']) {
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'boolean' => is_bool($value),
            'string', 'enum' => is_string($value) && $value !== '' && mb_strlen($value) <= 160,
            default => false,
        };

        if ($valid && $definition['tipo'] === 'enum' && isset($definition['options'])) {
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
            $this->invalid('ordenamientos', 'Los ordenamientos deben ser una lista.');
        }

        $sorts = [];
        foreach ($input as $index => $sort) {
            if (! is_array($sort) || ! is_string($sort['campo'] ?? null)
                || ! in_array($sort['direccion'] ?? null, ['asc', 'desc'], true)) {
                $this->invalid("ordenamientos.{$index}", 'Cada ordenamiento debe indicar campo y direccion válidos.');
            }

            $unknown = array_diff(array_keys($sort), ['campo', 'direccion']);
            if ($unknown !== []) {
                $this->invalid("ordenamientos.{$index}", 'Cada ordenamiento solo puede indicar campo y direccion.');
            }

            $field = $sort['campo'];
            if (! array_key_exists($field, $definition->sorts)) {
                $this->invalid("ordenamientos.{$index}.campo", 'El campo de ordenamiento no está permitido.');
            }
            $sorts[] = ['field' => $field, 'direction' => $sort['direccion']];
        }

        return $sorts;
    }

    /** @param array<string, array{label: string, tipo: string}> $allowed */
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
            $this->invalid('hasta', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if ($fromDate->diffInDays($toDate) > $definition->limits['max_range_days']) {
            $this->invalid('hasta', 'El rango de fechas supera el límite permitido.');
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

    /** @return array{campo: string, direccion: string} */
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
            if (($definition->metrics[$metric]['tipo'] ?? null) === 'quantity') {
                return true;
            }
        }

        return false;
    }

    private function defaultMetric(ReportSourceDefinition $definition): string
    {
        foreach (['stock_disponible', 'cantidad_movimientos'] as $preferred) {
            if (array_key_exists($preferred, $definition->metrics)) {
                return $preferred;
            }
        }

        return (string) array_key_first($definition->metrics);
    }

    private function invalid(string $key, string $message): never
    {
        throw new ReportQueryValidationException([$key => [$message]]);
    }
}
