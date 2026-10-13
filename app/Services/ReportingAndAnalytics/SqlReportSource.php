<?php

namespace App\Services\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/** Comparte paginación, filtros y agregación de las fuentes SQL del catálogo de negocio. */
abstract class SqlReportSource implements ReportSource
{
    abstract protected function baseQuery(): Builder;

    /** Permite recortar períodos calculados antes de aplicar filtros comunes. */
    protected function prepareRecords(ReportQueryData $query): Builder
    {
        return $this->baseQuery();
    }

    /** @return array<string, string> */
    abstract protected function metricExpressions(): array;

    /** Evita fusionar entidades distintas que comparten un nombre. @return array<string, string> */
    protected function dimensionKeys(): array
    {
        return ['flock' => 'flock_id', 'poultry_house' => 'poultry_house_id', 'production_unit' => 'production_unit_id'];
    }

    /** @param array<string, array<string, mixed>> $columns @param array<string, array<string, mixed>> $metrics */
    protected function describe(string $key, string $label, string $description, string $permission, array $columns, array $metrics, array $extraFilters = [], array $standardQueries = [], array $additionalPermissions = []): ReportSourceDefinition
    {
        $filters = $extraFilters;
        $groupings = ['day' => ['label' => 'Día', 'type' => 'date'], 'week' => ['label' => 'Semana', 'type' => 'date'], 'month' => ['label' => 'Mes', 'type' => 'date']];
        $sorts = [];
        foreach ($columns as $field => $column) {
            $sorts[$field] = ['label' => $column['label'], 'direction' => 'both'];
            if (str_ends_with($field, '_id')) {
                $filters[$field] = ['label' => $column['label'], 'type' => $column['type'], 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => $field];
            }
            if ($column['type'] === 'string' && ! str_ends_with($field, '_id') && ! in_array($field, ['notes', 'reason', 'row_key'], true)) {
                $groupings[$field] = ['label' => $column['label'], 'type' => 'dimension'];
            }
        }
        foreach ([...$metrics, ...$groupings] as $field => $value) {
            $sorts[$field] = ['label' => $value['label'], 'direction' => 'both'];
        }

        return new ReportSourceDefinition(key: $key, definitionVersion: '1.0', label: $label, description: $description,
            permission: $permission, columns: $columns, filters: $filters, groupings: $groupings, metrics: $metrics, sorts: $sorts,
            formats: ['xlsx', 'pdf'], limits: ['max_page_size' => 100, 'max_range_days' => 0, 'max_export_rows' => 50000],
            defaultSort: 'date:desc', defaultColumns: array_values(array_filter(array_keys($columns), fn (string $field): bool => ! str_ends_with($field, '_id'))),
            standardQueries: $standardQueries, additionalPermissions: $additionalPermissions);
    }

    public function preview(ReportQueryData $query): ReportResultData
    {
        $columns = $this->resultColumns($query);
        $paginator = $this->query($query)->paginate($query->perPage, ['*'], 'page', $query->page);

        return new ReportResultData($query->sourceKey, $query->definitionVersion, $columns,
            $paginator->getCollection()->map(fn (object $row): array => $this->present($row, $columns))->all(),
            [], $this->units($columns), $paginator->currentPage(), $paginator->perPage(), $paginator->total(), $paginator->lastPage(), now());
    }

    public function rows(ReportQueryData $query): LazyCollection
    {
        $columns = $this->resultColumns($query);

        return $this->query($query)->lazy(500)->map(fn (object $row): array => $this->present($row, $columns));
    }

    /** Opciones obtenidas de hechos autorizados, incluidos registros históricos. */
    public function options(string $field, string $search, int $page): array
    {
        $label = preg_replace('/_id$/', '', $field);
        if (! array_key_exists($label, $this->definition()->columns)) {
            $label = $field;
        }
        $builder = DB::query()->fromSub($this->baseQuery(), 'options')->whereNotNull($field);
        if ($search !== '') {
            $builder->whereRaw('CAST("'.$label.'" AS TEXT) ILIKE ?', ['%'.$search.'%']);
        }
        $result = $builder->select([$field.' as value', $label.' as label'])->distinct()->orderBy('label')->orderBy('value')->paginate(50, ['*'], 'page', $page);

        return ['data' => $result->items(), 'pagination' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total()]];
    }

    protected function query(ReportQueryData $query): Builder
    {
        $timezone = config('lots.timezone', 'America/Montevideo');
        $dates = DB::query()->fromSub($this->prepareRecords($query), 'records')->select('records.*');
        foreach (['day', 'week', 'month'] as $period) {
            $dates->selectRaw("date_trunc('{$period}', records.date AT TIME ZONE ?)::date as {$period}", [$timezone]);
        }
        $builder = DB::query()->fromSub($dates, 'report_rows');
        foreach ($query->filters as $filter) {
            match ($filter['operator']) {
                'in' => $builder->whereIn($filter['field'], $filter['value']),
                'not_in' => $builder->whereNotIn($filter['field'], $filter['value']),
                'neq' => $builder->where($filter['field'], '<>', $filter['value']),
                default => $builder->where($filter['field'], '=', $filter['value']),
            };
        }
        if ($query->from !== null) {
            $builder->where('date', '>=', Carbon::parse($query->from, $timezone)->startOfDay()->toIso8601String());
        }
        if ($query->to !== null) {
            $builder->where('date', '<', Carbon::parse($query->to, $timezone)->addDay()->startOfDay()->toIso8601String());
        }
        $grouped = $query->groupings !== [] || $query->metrics !== [];
        if ($grouped) {
            $builder->select($query->groupings);
            $keys = [];
            foreach ($query->groupings as $dimension) {
                $keys[] = $dimension;
                if (isset($this->dimensionKeys()[$dimension])) {
                    $keys[] = $this->dimensionKeys()[$dimension];
                }
            }
            foreach ($query->metrics as $metric) {
                $builder->selectRaw($this->metricExpressions()[$metric].' as "'.$metric.'"');
            }
            if ($keys !== []) {
                $builder->groupBy(array_values(array_unique($keys)));
            }
        } else {
            $builder->select($query->columns);
        }
        foreach ($query->sorts as $sort) {
            $builder->orderBy($sort['field'], $sort['direction']);
        }
        foreach ($grouped ? $keys : ['row_key'] as $key) {
            $builder->orderBy($key);
        }

        return $builder;
    }

    private function resultColumns(ReportQueryData $query): array
    {
        return $query->groupings !== [] || $query->metrics !== [] ? [...$query->groupings, ...$query->metrics] : $query->columns;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function present(object $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;
            $type = ($this->definition()->columns[$column] ?? $this->definition()->metrics[$column] ?? [])['type'] ?? 'string';
            $result[$column] = $value === null ? null : (in_array($type, ['number', 'quantity', 'money'], true) ? (string) $value : $value);
        }

        return $result;
    }

    private function units(array $columns): array
    {
        $units = [];
        foreach ($columns as $column) {
            $metadata = $this->definition()->columns[$column] ?? $this->definition()->metrics[$column] ?? [];
            if (isset($metadata['unit'])) {
                $units[$column] = $metadata['unit'];
            }
        }

        return $units;
    }
}
