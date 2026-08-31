<?php

namespace App\Modules\Inventory\Application\PublicApi\Reporting;

use App\Models\Inventory\StockBalance;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportResultData;
use App\Modules\ReportingAndAnalytics\Domain\Contracts\ReportSource;
use App\Modules\ReportingAndAnalytics\Domain\Data\ReportSourceDefinition;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class InventoryStockBalancesReportSource implements ReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return new ReportSourceDefinition(
            key: 'inventario.saldos-stock',
            definitionVersion: '1.0',
            label: 'Saldos de inventario',
            description: 'Stock físico, reservado, disponible y mínimo por producto y ubicación.',
            permission: 'inventory.view',
            columns: [
                'producto_id' => ['label' => 'ID de producto', 'tipo' => 'integer'],
                'producto' => ['label' => 'Producto', 'tipo' => 'string'],
                'unidad_base' => ['label' => 'Unidad base', 'tipo' => 'string'],
                'ubicacion_stock_id' => ['label' => 'ID de ubicación', 'tipo' => 'integer'],
                'ubicacion_stock' => ['label' => 'Ubicación', 'tipo' => 'string'],
                'cantidad_fisica' => ['label' => 'Stock físico', 'tipo' => 'number', 'unit' => 'unidad_base'],
                'cantidad_reservada' => ['label' => 'Stock reservado', 'tipo' => 'number', 'unit' => 'unidad_base'],
                'cantidad_disponible' => ['label' => 'Stock disponible', 'tipo' => 'number', 'unit' => 'unidad_base'],
                'cantidad_minima' => ['label' => 'Stock mínimo', 'tipo' => 'number', 'unit' => 'unidad_base'],
                'bajo_minimo' => ['label' => 'Bajo mínimo', 'tipo' => 'boolean'],
            ],
            filters: [
                'producto_id' => ['label' => 'Producto', 'tipo' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'ubicacion_stock_id' => ['label' => 'Ubicación', 'tipo' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'unidad_base' => ['label' => 'Unidad base', 'tipo' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => ['unit', 'kg', 'g', 'l', 'ml', 'dose']],
                'bajo_minimo' => ['label' => 'Bajo mínimo', 'tipo' => 'boolean', 'operators' => ['eq']],
            ],
            groupings: [
                'producto' => ['label' => 'Producto', 'tipo' => 'dimension'],
                'ubicacion_stock' => ['label' => 'Ubicación', 'tipo' => 'dimension'],
                'unidad_base' => ['label' => 'Unidad base', 'tipo' => 'dimension'],
            ],
            metrics: [
                'cantidad_bajo_minimo' => ['label' => 'Cantidad bajo mínimo', 'tipo' => 'count'],
                'stock_fisico' => ['label' => 'Stock físico', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
                'stock_reservado' => ['label' => 'Stock reservado', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
                'stock_disponible' => ['label' => 'Stock disponible', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
            ],
            sorts: [
                'producto' => ['label' => 'Producto', 'direccion' => 'asc'],
                'ubicacion_stock' => ['label' => 'Ubicación', 'direccion' => 'asc'],
                'unidad_base' => ['label' => 'Unidad base', 'direccion' => 'asc'],
                'cantidad_fisica' => ['label' => 'Stock físico', 'direccion' => 'both'],
                'cantidad_disponible' => ['label' => 'Stock disponible', 'direccion' => 'both'],
            ],
            formats: ['xlsx', 'pdf'],
            limits: ['max_page_size' => 100, 'max_range_days' => 366, 'max_export_rows' => 50000],
            defaultSort: 'producto:asc',
        );
    }

    public function preview(ReportQueryData $query): ReportResultData
    {
        $builder = $this->applyFilters($this->baseQuery(), $query);
        $grouped = $query->groupings !== [] || $query->metrics !== [];

        if ($grouped) {
            $builder = $this->applyGrouping($builder, $query);
            $resultColumns = [...$query->groupings, ...$query->metrics];
        } else {
            $resultColumns = $query->columns;
            $builder->select($this->detailSelects());
        }

        $builder = $this->applySorts($builder, $query, $grouped);
        $paginator = $builder->paginate($query->perPage, ['*'], 'page', $query->page);
        $rows = $paginator->getCollection()
            ->map(fn (object $row): array => $this->rowToArray($row, $resultColumns))
            ->values()
            ->all();

        return new ReportResultData(
            sourceKey: $query->sourceKey,
            definitionVersion: $query->definitionVersion,
            columns: $resultColumns,
            rows: $rows,
            aggregates: [],
            units: $this->units($resultColumns),
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
            generatedAt: now(),
        );
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function rows(ReportQueryData $query): LazyCollection
    {
        $builder = $this->applyFilters($this->baseQuery(), $query);
        $grouped = $query->groupings !== [] || $query->metrics !== [];
        $resultColumns = $grouped ? [...$query->groupings, ...$query->metrics] : $query->columns;

        if ($grouped) {
            $builder = $this->applyGrouping($builder, $query);
        } else {
            $builder->select($this->detailSelects());
        }

        return $this->applySorts($builder, $query, $grouped)
            ->lazy(500)
            ->map(fn (object $row): array => $this->rowToArray($row, $resultColumns));
    }

    private function baseQuery(): QueryBuilder
    {
        return StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_balances.stock_location_id')
            ->where('products.stock_tracked', true)
            ->getQuery();
    }

    /** @return list<string> */
    private function detailSelects(): array
    {
        return [
            'products.id as producto_id',
            'products.name as producto',
            'products.base_unit as unidad_base',
            'stock_locations.id as ubicacion_stock_id',
            'stock_locations.name as ubicacion_stock',
            'stock_balances.on_hand_quantity as cantidad_fisica',
            'stock_balances.reserved_quantity as cantidad_reservada',
            DB::raw('(stock_balances.on_hand_quantity - stock_balances.reserved_quantity) as cantidad_disponible'),
            'stock_balances.minimum_quantity as cantidad_minima',
            DB::raw('(stock_balances.on_hand_quantity - stock_balances.reserved_quantity) < stock_balances.minimum_quantity as bajo_minimo'),
        ];
    }

    private function applyFilters(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $fields = [
            'producto_id' => 'products.id',
            'ubicacion_stock_id' => 'stock_locations.id',
            'unidad_base' => 'products.base_unit',
        ];

        foreach ($query->filters as $filter) {
            if ($filter['field'] === 'bajo_minimo') {
                $operator = $filter['value'] ? '<' : '>=';
                $builder->whereRaw("(stock_balances.on_hand_quantity - stock_balances.reserved_quantity) {$operator} stock_balances.minimum_quantity");

                continue;
            }

            $column = $fields[$filter['field']];
            match ($filter['operator']) {
                'eq' => $builder->where($column, $filter['value']),
                'neq' => $builder->where($column, '<>', $filter['value']),
                'in' => $builder->whereIn($column, $filter['value']),
                'not_in' => $builder->whereNotIn($column, $filter['value']),
            };
        }

        return $builder;
    }

    private function applyGrouping(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $groupFields = [
            'producto' => ['products.name as producto', 'products.name'],
            'ubicacion_stock' => ['stock_locations.name as ubicacion_stock', 'stock_locations.name'],
            'unidad_base' => ['products.base_unit as unidad_base', 'products.base_unit'],
        ];
        $selects = [];
        $groupBy = [];
        foreach ($query->groupings as $grouping) {
            [$select, $expression] = $groupFields[$grouping];
            $selects[] = $select;
            $groupBy[] = $expression;
        }

        $metricExpressions = [
            'cantidad_bajo_minimo' => 'COUNT(*) FILTER (WHERE (stock_balances.on_hand_quantity - stock_balances.reserved_quantity) < stock_balances.minimum_quantity)',
            'stock_fisico' => 'SUM(stock_balances.on_hand_quantity)',
            'stock_reservado' => 'SUM(stock_balances.reserved_quantity)',
            'stock_disponible' => 'SUM(stock_balances.on_hand_quantity - stock_balances.reserved_quantity)',
        ];
        foreach ($query->metrics as $metric) {
            $selects[] = DB::raw("{$metricExpressions[$metric]} as {$metric}");
        }

        return $builder->select($selects)->groupBy($groupBy);
    }

    private function applySorts(QueryBuilder $builder, ReportQueryData $query, bool $grouped): QueryBuilder
    {
        $aliases = [
            'producto' => 'producto',
            'ubicacion_stock' => 'ubicacion_stock',
            'unidad_base' => 'unidad_base',
            'cantidad_fisica' => 'cantidad_fisica',
            'cantidad_disponible' => 'cantidad_disponible',
        ];
        foreach ($query->sorts as $sort) {
            if ($grouped && ! in_array($sort['field'], [...$query->groupings, ...$query->metrics], true)) {
                continue;
            }
            $builder->orderBy($aliases[$sort['field']], $sort['direction']);
        }

        return $builder->orderBy($grouped ? $query->groupings[0] ?? $query->metrics[0] ?? 'unidad_base' : 'producto_id');
    }

    /** @param list<string> $columns @return array<string, mixed> */
    private function rowToArray(object $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;
            if (in_array($column, ['cantidad_fisica', 'cantidad_reservada', 'cantidad_disponible', 'cantidad_minima', 'stock_fisico', 'stock_reservado', 'stock_disponible'], true)) {
                $value = (string) $value;
            }
            if ($column === 'bajo_minimo') {
                $value = (bool) $value;
            }
            $result[$column] = $value;
        }

        return $result;
    }

    /** @param list<string> $columns @return array<string, string|null> */
    private function units(array $columns): array
    {
        $units = [];
        foreach ($columns as $column) {
            if (in_array($column, ['cantidad_fisica', 'cantidad_reservada', 'cantidad_disponible', 'cantidad_minima', 'stock_fisico', 'stock_reservado', 'stock_disponible'], true)) {
                $units[$column] = 'unidad_base';
            }
        }

        return $units;
    }
}
