<?php

namespace App\Services\Inventory;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Enums\Inventory\InventoryMovementType;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\Inventory\InventoryMovementLine;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class InventoryMovementsReportSource implements ReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return new ReportSourceDefinition(
            key: 'inventory.movements',
            definitionVersion: '1.1',
            label: 'Movimientos de inventario',
            description: 'Ingresos, salidas, pérdidas, ajustes y transferencias por fecha y unidad base.',
            permission: 'inventory.view',
            columns: [
                'date' => ['label' => 'Fecha', 'type' => 'datetime'],
                'type' => ['label' => 'Tipo', 'type' => 'string'],
                'product_id' => ['label' => 'ID de producto', 'type' => 'integer'],
                'product' => ['label' => 'Producto', 'type' => 'string'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'string'],
                'stock_location_id' => ['label' => 'ID de ubicación', 'type' => 'integer'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'string'],
                'production_unit_id' => ['label' => 'ID de unidad productiva', 'type' => 'integer'],
                'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
                'supplier_id' => ['label' => 'ID de proveedor', 'type' => 'integer'],
                'supplier' => ['label' => 'Proveedor', 'type' => 'string'],
                'reference_type' => ['label' => 'Tipo de referencia', 'type' => 'string'],
                'reference_id' => ['label' => 'Referencia', 'type' => 'string'],
                'physical_delta' => ['label' => 'Variación física', 'type' => 'number', 'unit' => 'base_unit'],
            ],
            filters: [
                'type' => ['label' => 'Tipo', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => array_map(static fn (InventoryMovementType $type): string => $type->value, InventoryMovementType::cases()), 'options_source' => 'movementTypes'],
                'product_id' => ['label' => 'Producto', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'products'],
                'stock_location_id' => ['label' => 'Ubicación', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'stockLocations'],
                'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'productionUnits'],
                'supplier_id' => ['label' => 'Proveedor', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options_source' => 'suppliers'],
            ],
            groupings: [
                'day' => ['label' => 'Día', 'type' => 'date'],
                'week' => ['label' => 'Semana', 'type' => 'date'],
                'month' => ['label' => 'Mes', 'type' => 'date'],
                'type' => ['label' => 'Tipo', 'type' => 'dimension'],
                'product' => ['label' => 'Producto', 'type' => 'dimension'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'dimension'],
                'production_unit' => ['label' => 'Unidad productiva', 'type' => 'dimension'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'dimension'],
            ],
            metrics: [
                'movement_count' => ['label' => 'Cantidad de movimientos', 'type' => 'count'],
                'received_quantity' => ['label' => 'Cantidad de ingresos', 'type' => 'quantity', 'unit' => 'base_unit'],
                'issued_quantity' => ['label' => 'Cantidad de salidas', 'type' => 'quantity', 'unit' => 'base_unit'],
                'lost_quantity' => ['label' => 'Cantidad de pérdidas', 'type' => 'quantity', 'unit' => 'base_unit'],
                'adjusted_quantity' => ['label' => 'Cantidad de ajustes', 'type' => 'quantity', 'unit' => 'base_unit'],
            ],
            sorts: [
                'date' => ['label' => 'Fecha', 'direction' => 'both'],
                'type' => ['label' => 'Tipo', 'direction' => 'asc'],
                'product' => ['label' => 'Producto', 'direction' => 'asc'],
                'stock_location' => ['label' => 'Ubicación', 'direction' => 'asc'],
                'base_unit' => ['label' => 'Unidad base', 'direction' => 'asc'],
            ],
            formats: ['xlsx', 'pdf'],
            limits: ['max_page_size' => 100, 'max_range_days' => 366, 'max_export_rows' => 50000],
            defaultSort: 'date:desc',
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
        return InventoryMovementLine::query()
            ->join('inventory_movements', 'inventory_movements.id', '=', 'inventory_movement_lines.inventory_movement_id')
            ->join('products', 'products.id', '=', 'inventory_movement_lines.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'inventory_movement_lines.stock_location_id')
            ->leftJoin('production_units', 'production_units.id', '=', 'stock_locations.production_unit_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'inventory_movements.supplier_id')
            ->getQuery();
    }

    /** @return list<string> */
    private function detailSelects(): array
    {
        return [
            'inventory_movements.occurred_at as date',
            'inventory_movements.type as type',
            'products.id as product_id',
            'products.name as product',
            'products.base_unit as base_unit',
            'stock_locations.id as stock_location_id',
            'stock_locations.name as stock_location',
            'production_units.id as production_unit_id',
            'production_units.name as production_unit',
            'suppliers.id as supplier_id',
            'suppliers.name as supplier',
            'inventory_movements.reference_type as reference_type',
            'inventory_movements.reference_id as reference_id',
            'inventory_movement_lines.on_hand_delta as physical_delta',
        ];
    }

    private function applyFilters(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $fields = [
            'type' => 'inventory_movements.type',
            'product_id' => 'products.id',
            'stock_location_id' => 'stock_locations.id',
            'production_unit_id' => 'production_units.id',
            'supplier_id' => 'inventory_movements.supplier_id',
        ];

        foreach ($query->filters as $filter) {
            $column = $fields[$filter['field']];
            match ($filter['operator']) {
                'eq' => $builder->where($column, $filter['value']),
                'neq' => $builder->where($column, '<>', $filter['value']),
                'in' => $builder->whereIn($column, $filter['value']),
                'not_in' => $builder->whereNotIn($column, $filter['value']),
            };
        }
        if ($query->from !== null) {
            $builder->whereDate('inventory_movements.occurred_at', '>=', $query->from);
        }
        if ($query->to !== null) {
            $builder->whereDate('inventory_movements.occurred_at', '<=', $query->to);
        }

        return $builder;
    }

    private function applyGrouping(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $groupFields = [
            'day' => ["DATE_TRUNC('day', inventory_movements.occurred_at)", "DATE_TRUNC('day', inventory_movements.occurred_at)"],
            'week' => ["DATE_TRUNC('week', inventory_movements.occurred_at)", "DATE_TRUNC('week', inventory_movements.occurred_at)"],
            'month' => ["DATE_TRUNC('month', inventory_movements.occurred_at)", "DATE_TRUNC('month', inventory_movements.occurred_at)"],
            'type' => ['inventory_movements.type', 'inventory_movements.type'],
            'product' => ['products.name', 'products.name'],
            'stock_location' => ['stock_locations.name', 'stock_locations.name'],
            'production_unit' => ['production_units.name', 'production_units.name'],
            'base_unit' => ['products.base_unit', 'products.base_unit'],
        ];
        $aliases = [
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'type' => 'type',
            'product' => 'product',
            'stock_location' => 'stock_location',
            'production_unit' => 'production_unit',
            'base_unit' => 'base_unit',
        ];
        $selects = [];
        $groupBy = [];
        foreach ($query->groupings as $grouping) {
            [$expression, $groupExpression] = $groupFields[$grouping];
            $selects[] = DB::raw("{$expression} as {$aliases[$grouping]}");
            $groupBy[] = DB::raw($groupExpression);
        }

        $metricExpressions = [
            'movement_count' => 'COUNT(DISTINCT inventory_movements.id)',
            'received_quantity' => "SUM(CASE WHEN inventory_movements.type = 'receipt' THEN inventory_movement_lines.on_hand_delta ELSE 0 END)",
            'issued_quantity' => "SUM(CASE WHEN inventory_movements.type = 'issue' THEN ABS(inventory_movement_lines.on_hand_delta) ELSE 0 END)",
            'lost_quantity' => "SUM(CASE WHEN inventory_movements.type = 'loss' THEN ABS(inventory_movement_lines.on_hand_delta) ELSE 0 END)",
            'adjusted_quantity' => "SUM(CASE WHEN inventory_movements.type = 'adjustment' THEN inventory_movement_lines.on_hand_delta ELSE 0 END)",
        ];
        foreach ($query->metrics as $metric) {
            $selects[] = DB::raw("{$metricExpressions[$metric]} as {$metric}");
        }

        return $builder->select($selects)->groupBy($groupBy);
    }

    private function applySorts(QueryBuilder $builder, ReportQueryData $query, bool $grouped): QueryBuilder
    {
        $aliases = [
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'date' => 'date',
            'type' => 'type',
            'product' => 'product',
            'stock_location' => 'stock_location',
            'base_unit' => 'base_unit',
        ];
        foreach ($query->sorts as $sort) {
            if ($grouped && ! in_array($sort['field'], [...$query->groupings, ...$query->metrics], true)) {
                continue;
            }
            $builder->orderBy($aliases[$sort['field']], $sort['direction']);
        }

        return $builder->orderBy($grouped ? $query->groupings[0] ?? $query->metrics[0] ?? 'date' : 'date');
    }

    /** @param list<string> $columns @return array<string, mixed> */
    private function rowToArray(object $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;
            if (in_array($column, ['physical_delta', 'received_quantity', 'issued_quantity', 'lost_quantity', 'adjusted_quantity'], true)) {
                $value = (string) $value;
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
            if (in_array($column, ['physical_delta', 'received_quantity', 'issued_quantity', 'lost_quantity', 'adjusted_quantity'], true)) {
                $units[$column] = 'base_unit';
            }
        }

        return $units;
    }
}
