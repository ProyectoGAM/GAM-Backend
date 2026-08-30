<?php

namespace App\Modules\Inventory\Application\PublicApi\Reporting;

use App\Models\Inventory\InventoryMovementLine;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportResultData;
use App\Modules\ReportingAndAnalytics\Domain\Contracts\ReportSource;
use App\Modules\ReportingAndAnalytics\Domain\Data\ReportSourceDefinition;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class InventoryMovementsReportSource implements ReportSource
{
    public function definition(): ReportSourceDefinition
    {
        return new ReportSourceDefinition(
            key: 'inventory.movements',
            definitionVersion: '1.0',
            label: 'Movimientos de inventario',
            description: 'Ingresos, salidas, pérdidas, ajustes y reservas por fecha y unidad base.',
            permission: 'inventory.view',
            columns: [
                'date' => ['label' => 'Fecha', 'type' => 'datetime'],
                'type' => ['label' => 'Tipo', 'type' => 'string'],
                'product_id' => ['label' => 'ID de producto', 'type' => 'integer'],
                'product' => ['label' => 'Producto', 'type' => 'string'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'string'],
                'stock_location_id' => ['label' => 'ID de ubicación', 'type' => 'integer'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'string'],
                'supplier_id' => ['label' => 'ID de proveedor', 'type' => 'integer'],
                'supplier' => ['label' => 'Proveedor', 'type' => 'string'],
                'reference_type' => ['label' => 'Tipo de referencia', 'type' => 'string'],
                'reference_id' => ['label' => 'Referencia', 'type' => 'string'],
                'on_hand_delta' => ['label' => 'Variación física', 'type' => 'number', 'unit' => 'base_unit'],
                'reserved_delta' => ['label' => 'Variación reservada', 'type' => 'number', 'unit' => 'base_unit'],
            ],
            filters: [
                'type' => ['label' => 'Tipo', 'type' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => ['opening_balance', 'receipt', 'issue', 'loss', 'adjustment', 'transfer', 'reservation', 'release', 'consumption', 'reversal']],
                'product_id' => ['label' => 'Producto', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'stock_location_id' => ['label' => 'Ubicación', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'supplier_id' => ['label' => 'Proveedor', 'type' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ],
            groupings: [
                'day' => ['label' => 'Día', 'type' => 'date'],
                'week' => ['label' => 'Semana', 'type' => 'date'],
                'month' => ['label' => 'Mes', 'type' => 'date'],
                'type' => ['label' => 'Tipo', 'type' => 'dimension'],
                'product' => ['label' => 'Producto', 'type' => 'dimension'],
                'stock_location' => ['label' => 'Ubicación', 'type' => 'dimension'],
                'base_unit' => ['label' => 'Unidad base', 'type' => 'dimension'],
            ],
            metrics: [
                'movement_count' => ['label' => 'Cantidad de movimientos', 'type' => 'count'],
                'receipts_quantity' => ['label' => 'Cantidad de ingresos', 'type' => 'quantity', 'unit' => 'base_unit'],
                'issues_quantity' => ['label' => 'Cantidad de salidas', 'type' => 'quantity', 'unit' => 'base_unit'],
                'losses_quantity' => ['label' => 'Cantidad de pérdidas', 'type' => 'quantity', 'unit' => 'base_unit'],
                'adjustments_quantity' => ['label' => 'Cantidad de ajustes', 'type' => 'quantity', 'unit' => 'base_unit'],
                'reserved_quantity' => ['label' => 'Variación reservada', 'type' => 'quantity', 'unit' => 'base_unit'],
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
            'suppliers.id as supplier_id',
            'suppliers.name as supplier',
            'inventory_movements.reference_type as reference_type',
            'inventory_movements.reference_id as reference_id',
            'inventory_movement_lines.on_hand_delta as on_hand_delta',
            'inventory_movement_lines.reserved_delta as reserved_delta',
        ];
    }

    private function applyFilters(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $fields = [
            'type' => 'inventory_movements.type',
            'product_id' => 'products.id',
            'stock_location_id' => 'stock_locations.id',
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
            'base_unit' => ['products.base_unit', 'products.base_unit'],
        ];
        $aliases = [
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'type' => 'type',
            'product' => 'product',
            'stock_location' => 'stock_location',
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
            'receipts_quantity' => "SUM(CASE WHEN inventory_movements.type = 'receipt' THEN inventory_movement_lines.on_hand_delta ELSE 0 END)",
            'issues_quantity' => "SUM(CASE WHEN inventory_movements.type = 'issue' THEN ABS(inventory_movement_lines.on_hand_delta) ELSE 0 END)",
            'losses_quantity' => "SUM(CASE WHEN inventory_movements.type = 'loss' THEN ABS(inventory_movement_lines.on_hand_delta) ELSE 0 END)",
            'adjustments_quantity' => "SUM(CASE WHEN inventory_movements.type = 'adjustment' THEN inventory_movement_lines.on_hand_delta ELSE 0 END)",
            'reserved_quantity' => 'SUM(inventory_movement_lines.reserved_delta)',
        ];
        foreach ($query->metrics as $metric) {
            $selects[] = DB::raw("{$metricExpressions[$metric]} as {$metric}");
        }

        return $builder->select($selects)->groupBy($groupBy);
    }

    private function applySorts(QueryBuilder $builder, ReportQueryData $query, bool $grouped): QueryBuilder
    {
        $aliases = [
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
            if (in_array($column, ['on_hand_delta', 'reserved_delta', 'receipts_quantity', 'issues_quantity', 'losses_quantity', 'adjustments_quantity', 'reserved_quantity'], true)) {
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
            if (in_array($column, ['on_hand_delta', 'reserved_delta', 'receipts_quantity', 'issues_quantity', 'losses_quantity', 'adjustments_quantity', 'reserved_quantity'], true)) {
                $units[$column] = 'base_unit';
            }
        }

        return $units;
    }
}
