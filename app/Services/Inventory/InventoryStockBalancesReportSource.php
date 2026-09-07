<?php

namespace App\Services\Inventory;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\Inventory\StockBalance;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class InventoryStockBalancesReportSource implements ReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return new ReportSourceDefinition(
            key: 'inventory.stock-balances',
            definitionVersion: '2.1',
            label: 'Saldos de inventario',
            description: 'Stock disponible y mínimo por producto y ubicación.',
            permission: 'inventory.view',
            columns: [
                'product_id' => ['label' => 'ID de producto', 'type' => 'integer'],
                'product' => ['label' => 'Producto', 'type' => 'string'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'string'],
                'stock_location_id' => ['label' => 'ID de ubicación', 'type' => 'integer'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'string'],
                'production_unit_id' => ['label' => 'ID de unidad productiva', 'type' => 'integer'],
                'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
                'available_quantity' => ['label' => 'Stock disponible', 'type' => 'number', 'unit' => 'base_unit'],
                'minimum_quantity' => ['label' => 'Stock mínimo', 'type' => 'number', 'unit' => 'base_unit'],
                'below_minimum' => ['label' => 'Bajo mínimo', 'type' => 'boolean', 'options_source' => 'booleanValues'],
            ],
            filters: [
                'product_id' => ['label' => 'Producto', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'products'],
                'stock_location_id' => ['label' => 'Ubicación', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'stockLocations'],
                'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'productionUnits'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => array_map(static fn (BaseUnit $unit): string => $unit->value, BaseUnit::cases()), 'options_source' => 'baseUnits'],
                'below_minimum' => ['label' => 'Bajo mínimo', 'type' => 'boolean', 'operators' => ['eq'], 'options_source' => 'booleanValues'],
            ],
            groupings: [
                'product' => ['label' => 'Producto', 'type' => 'dimension'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'dimension'],
                'production_unit' => ['label' => 'Unidad productiva', 'type' => 'dimension'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'dimension'],
            ],
            metrics: [
                'below_minimum_count' => ['label' => 'Cantidad bajo mínimo', 'type' => 'count'],
                'available_stock' => ['label' => 'Stock disponible', 'type' => 'quantity', 'unit' => 'base_unit'],
            ],
            sorts: [
                'product' => ['label' => 'Producto', 'direction' => 'asc'],
                'stock_location' => ['label' => 'Ubicación', 'direction' => 'asc'],
                'base_unit' => ['label' => 'Unidad base', 'direction' => 'asc'],
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
            ->leftJoin('production_units', 'production_units.id', '=', 'stock_locations.production_unit_id')
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
            'production_units.id as production_unit_id',
            'production_units.name as production_unit',
            'stock_balances.on_hand_quantity as available_quantity',
            'stock_balances.minimum_quantity as minimum_quantity',
            DB::raw('stock_balances.on_hand_quantity < stock_balances.minimum_quantity as below_minimum'),
        ];
    }

    private function applyFilters(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $fields = [
            'product_id' => 'products.id',
            'stock_location_id' => 'stock_locations.id',
            'production_unit_id' => 'production_units.id',
            'base_unit' => 'products.base_unit',
        ];

        foreach ($query->filters as $filter) {
            if ($filter['field'] === 'below_minimum') {
                $operator = $filter['value'] ? '<' : '>=';
                $builder->whereRaw("stock_balances.on_hand_quantity {$operator} stock_balances.minimum_quantity");

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
            'production_unit' => ['production_units.name as production_unit', 'production_units.name'],
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
            'below_minimum_count' => 'COUNT(*) FILTER (WHERE stock_balances.on_hand_quantity < stock_balances.minimum_quantity)',
            'available_stock' => 'SUM(stock_balances.on_hand_quantity)',
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
            'production_unit' => 'production_unit',
            'base_unit' => 'base_unit',
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
            if (in_array($column, ['available_quantity', 'minimum_quantity', 'available_stock'], true)) {
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
            if (in_array($column, ['available_quantity', 'minimum_quantity', 'available_stock'], true)) {
                $units[$column] = 'base_unit';
            }
        }

        return $units;
    }
}
