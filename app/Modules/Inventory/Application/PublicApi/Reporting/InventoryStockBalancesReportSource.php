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
            key: 'inventory.stock-balances',
            definitionVersion: '1.0',
            label: 'Saldos de inventario',
            description: 'Stock físico, reservado, disponible y mínimo por producto y ubicación.',
            permission: 'inventory.view',
            columns: [
                'product_id' => ['label' => 'ID de producto', 'type' => 'integer'],
                'product' => ['label' => 'Producto', 'type' => 'string'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'string'],
                'stock_location_id' => ['label' => 'ID de ubicación', 'type' => 'integer'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'string'],
                'on_hand_quantity' => ['label' => 'Stock físico', 'type' => 'number', 'unit' => 'base_unit'],
                'reserved_quantity' => ['label' => 'Stock reservado', 'type' => 'number', 'unit' => 'base_unit'],
                'available_quantity' => ['label' => 'Stock disponible', 'type' => 'number', 'unit' => 'base_unit'],
                'minimum_quantity' => ['label' => 'Stock mínimo', 'type' => 'number', 'unit' => 'base_unit'],
                'below_minimum' => ['label' => 'Bajo mínimo', 'type' => 'boolean'],
            ],
            filters: [
                'product_id' => ['label' => 'Producto', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'stock_location_id' => ['label' => 'Ubicación', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => ['unit', 'kg', 'g', 'l', 'ml', 'dose']],
                'below_minimum' => ['label' => 'Bajo mínimo', 'type' => 'boolean', 'operators' => ['eq']],
            ],
            groupings: [
                'product' => ['label' => 'Producto', 'type' => 'dimension'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'dimension'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'dimension'],
            ],
            metrics: [
                'below_minimum_count' => ['label' => 'Cantidad bajo mínimo', 'type' => 'count'],
                'physical_stock' => ['label' => 'Stock físico', 'type' => 'quantity', 'unit' => 'base_unit'],
                'reserved_stock' => ['label' => 'Stock reservado', 'type' => 'quantity', 'unit' => 'base_unit'],
                'available_stock' => ['label' => 'Stock disponible', 'type' => 'quantity', 'unit' => 'base_unit'],
            ],
            sorts: [
                'product' => ['label' => 'Producto', 'direction' => 'asc'],
                'stock_location' => ['label' => 'Ubicación', 'direction' => 'asc'],
                'base_unit' => ['label' => 'Unidad base', 'direction' => 'asc'],
                'on_hand_quantity' => ['label' => 'Stock físico', 'direction' => 'both'],
                'available_quantity' => ['label' => 'Stock disponible', 'direction' => 'both'],
            ],
            formats: ['xlsx', 'pdf'],
            limits: ['max_page_size' => 100, 'max_range_days' => 366, 'max_export_rows' => 50000],
            defaultSort: 'product:asc',
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
            'products.id as product_id',
            'products.name as product',
            'products.base_unit as base_unit',
            'stock_locations.id as stock_location_id',
            'stock_locations.name as stock_location',
            'stock_balances.on_hand_quantity as on_hand_quantity',
            'stock_balances.reserved_quantity as reserved_quantity',
            DB::raw('(stock_balances.on_hand_quantity - stock_balances.reserved_quantity) as available_quantity'),
            'stock_balances.minimum_quantity as minimum_quantity',
            DB::raw('(stock_balances.on_hand_quantity - stock_balances.reserved_quantity) < stock_balances.minimum_quantity as below_minimum'),
        ];
    }

    private function applyFilters(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $fields = [
            'product_id' => 'products.id',
            'stock_location_id' => 'stock_locations.id',
            'base_unit' => 'products.base_unit',
        ];

        foreach ($query->filters as $filter) {
            if ($filter['field'] === 'below_minimum') {
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
            'product' => ['products.name as product', 'products.name'],
            'stock_location' => ['stock_locations.name as stock_location', 'stock_locations.name'],
            'base_unit' => ['products.base_unit as base_unit', 'products.base_unit'],
        ];
        $selects = [];
        $groupBy = [];
        foreach ($query->groupings as $grouping) {
            [$select, $expression] = $groupFields[$grouping];
            $selects[] = $select;
            $groupBy[] = $expression;
        }

        $metricExpressions = [
            'below_minimum_count' => 'COUNT(*) FILTER (WHERE (stock_balances.on_hand_quantity - stock_balances.reserved_quantity) < stock_balances.minimum_quantity)',
            'physical_stock' => 'SUM(stock_balances.on_hand_quantity)',
            'reserved_stock' => 'SUM(stock_balances.reserved_quantity)',
            'available_stock' => 'SUM(stock_balances.on_hand_quantity - stock_balances.reserved_quantity)',
        ];
        foreach ($query->metrics as $metric) {
            $selects[] = DB::raw("{$metricExpressions[$metric]} as {$metric}");
        }

        return $builder->select($selects)->groupBy($groupBy);
    }

    private function applySorts(QueryBuilder $builder, ReportQueryData $query, bool $grouped): QueryBuilder
    {
        $aliases = [
            'product' => 'product',
            'stock_location' => 'stock_location',
            'base_unit' => 'base_unit',
            'on_hand_quantity' => 'on_hand_quantity',
            'available_quantity' => 'available_quantity',
        ];
        foreach ($query->sorts as $sort) {
            if ($grouped && ! in_array($sort['field'], [...$query->groupings, ...$query->metrics], true)) {
                continue;
            }
            $builder->orderBy($aliases[$sort['field']], $sort['direction']);
        }

        return $builder->orderBy($grouped ? $query->groupings[0] ?? $query->metrics[0] ?? 'base_unit' : 'product_id');
    }

    /** @param list<string> $columns @return array<string, mixed> */
    private function rowToArray(object $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;
            if (in_array($column, ['on_hand_quantity', 'reserved_quantity', 'available_quantity', 'minimum_quantity', 'physical_stock', 'reserved_stock', 'available_stock'], true)) {
                $value = (string) $value;
            }
            if ($column === 'below_minimum') {
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
            if (in_array($column, ['on_hand_quantity', 'reserved_quantity', 'available_quantity', 'minimum_quantity', 'physical_stock', 'reserved_stock', 'available_stock'], true)) {
                $units[$column] = 'base_unit';
            }
        }

        return $units;
    }
}
