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
            key: 'inventario.movimientos',
            definitionVersion: '1.0',
            label: 'Movimientos de inventario',
            description: 'Ingresos, salidas, pérdidas, ajustes y reservas por fecha y unidad base.',
            permission: 'inventory.view',
            columns: [
                'fecha' => ['label' => 'Fecha', 'tipo' => 'datetime'],
                'tipo' => ['label' => 'Tipo', 'tipo' => 'string'],
                'producto_id' => ['label' => 'ID de producto', 'tipo' => 'integer'],
                'producto' => ['label' => 'Producto', 'tipo' => 'string'],
                'unidad_base' => ['label' => 'Unidad base', 'tipo' => 'string'],
                'ubicacion_stock_id' => ['label' => 'ID de ubicación', 'tipo' => 'integer'],
                'ubicacion_stock' => ['label' => 'Ubicación', 'tipo' => 'string'],
                'proveedor_id' => ['label' => 'ID de proveedor', 'tipo' => 'integer'],
                'proveedor' => ['label' => 'Proveedor', 'tipo' => 'string'],
                'tipo_referencia' => ['label' => 'Tipo de referencia', 'tipo' => 'string'],
                'referencia_id' => ['label' => 'Referencia', 'tipo' => 'string'],
                'variacion_fisica' => ['label' => 'Variación física', 'tipo' => 'number', 'unit' => 'unidad_base'],
                'variacion_reservada' => ['label' => 'Variación reservada', 'tipo' => 'number', 'unit' => 'unidad_base'],
            ],
            filters: [
                'tipo' => ['label' => 'Tipo', 'tipo' => 'enum', 'operators' => ['eq', 'neq', 'in', 'not_in'], 'options' => ['opening_balance', 'receipt', 'issue', 'loss', 'adjustment', 'transfer', 'reservation', 'release', 'consumption', 'reversal']],
                'producto_id' => ['label' => 'Producto', 'tipo' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'ubicacion_stock_id' => ['label' => 'Ubicación', 'tipo' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
                'proveedor_id' => ['label' => 'Proveedor', 'tipo' => 'integer', 'operators' => ['eq', 'neq', 'in', 'not_in']],
            ],
            groupings: [
                'dia' => ['label' => 'Día', 'tipo' => 'fecha'],
                'semana' => ['label' => 'Semana', 'tipo' => 'fecha'],
                'mes' => ['label' => 'Mes', 'tipo' => 'fecha'],
                'tipo' => ['label' => 'Tipo', 'tipo' => 'dimension'],
                'producto' => ['label' => 'Producto', 'tipo' => 'dimension'],
                'ubicacion_stock' => ['label' => 'Ubicación', 'tipo' => 'dimension'],
                'unidad_base' => ['label' => 'Unidad base', 'tipo' => 'dimension'],
            ],
            metrics: [
                'cantidad_movimientos' => ['label' => 'Cantidad de movimientos', 'tipo' => 'count'],
                'cantidad_ingresos' => ['label' => 'Cantidad de ingresos', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
                'cantidad_salidas' => ['label' => 'Cantidad de salidas', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
                'cantidad_perdidas' => ['label' => 'Cantidad de pérdidas', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
                'cantidad_ajustes' => ['label' => 'Cantidad de ajustes', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
                'cantidad_reservada' => ['label' => 'Variación reservada', 'tipo' => 'quantity', 'unit' => 'unidad_base'],
            ],
            sorts: [
                'fecha' => ['label' => 'Fecha', 'direccion' => 'both'],
                'tipo' => ['label' => 'Tipo', 'direccion' => 'asc'],
                'producto' => ['label' => 'Producto', 'direccion' => 'asc'],
                'ubicacion_stock' => ['label' => 'Ubicación', 'direccion' => 'asc'],
                'unidad_base' => ['label' => 'Unidad base', 'direccion' => 'asc'],
            ],
            formats: ['xlsx', 'pdf'],
            limits: ['max_page_size' => 100, 'max_range_days' => 366, 'max_export_rows' => 50000],
            defaultSort: 'fecha:desc',
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
            'inventory_movements.occurred_at as fecha',
            'inventory_movements.type as tipo',
            'products.id as producto_id',
            'products.name as producto',
            'products.base_unit as unidad_base',
            'stock_locations.id as ubicacion_stock_id',
            'stock_locations.name as ubicacion_stock',
            'suppliers.id as proveedor_id',
            'suppliers.name as proveedor',
            'inventory_movements.reference_type as tipo_referencia',
            'inventory_movements.reference_id as referencia_id',
            'inventory_movement_lines.on_hand_delta as variacion_fisica',
            'inventory_movement_lines.reserved_delta as variacion_reservada',
        ];
    }

    private function applyFilters(QueryBuilder $builder, ReportQueryData $query): QueryBuilder
    {
        $fields = [
            'tipo' => 'inventory_movements.type',
            'producto_id' => 'products.id',
            'ubicacion_stock_id' => 'stock_locations.id',
            'proveedor_id' => 'inventory_movements.supplier_id',
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
            'dia' => ["DATE_TRUNC('day', inventory_movements.occurred_at)", "DATE_TRUNC('day', inventory_movements.occurred_at)"],
            'semana' => ["DATE_TRUNC('week', inventory_movements.occurred_at)", "DATE_TRUNC('week', inventory_movements.occurred_at)"],
            'mes' => ["DATE_TRUNC('month', inventory_movements.occurred_at)", "DATE_TRUNC('month', inventory_movements.occurred_at)"],
            'tipo' => ['inventory_movements.type', 'inventory_movements.type'],
            'producto' => ['products.name', 'products.name'],
            'ubicacion_stock' => ['stock_locations.name', 'stock_locations.name'],
            'unidad_base' => ['products.base_unit', 'products.base_unit'],
        ];
        $aliases = [
            'dia' => 'dia',
            'semana' => 'semana',
            'mes' => 'mes',
            'tipo' => 'tipo',
            'producto' => 'producto',
            'ubicacion_stock' => 'ubicacion_stock',
            'unidad_base' => 'unidad_base',
        ];
        $selects = [];
        $groupBy = [];
        foreach ($query->groupings as $grouping) {
            [$expression, $groupExpression] = $groupFields[$grouping];
            $selects[] = DB::raw("{$expression} as {$aliases[$grouping]}");
            $groupBy[] = DB::raw($groupExpression);
        }

        $metricExpressions = [
            'cantidad_movimientos' => 'COUNT(DISTINCT inventory_movements.id)',
            'cantidad_ingresos' => "SUM(CASE WHEN inventory_movements.type = 'receipt' THEN inventory_movement_lines.on_hand_delta ELSE 0 END)",
            'cantidad_salidas' => "SUM(CASE WHEN inventory_movements.type = 'issue' THEN ABS(inventory_movement_lines.on_hand_delta) ELSE 0 END)",
            'cantidad_perdidas' => "SUM(CASE WHEN inventory_movements.type = 'loss' THEN ABS(inventory_movement_lines.on_hand_delta) ELSE 0 END)",
            'cantidad_ajustes' => "SUM(CASE WHEN inventory_movements.type = 'adjustment' THEN inventory_movement_lines.on_hand_delta ELSE 0 END)",
            'cantidad_reservada' => 'SUM(inventory_movement_lines.reserved_delta)',
        ];
        foreach ($query->metrics as $metric) {
            $selects[] = DB::raw("{$metricExpressions[$metric]} as {$metric}");
        }

        return $builder->select($selects)->groupBy($groupBy);
    }

    private function applySorts(QueryBuilder $builder, ReportQueryData $query, bool $grouped): QueryBuilder
    {
        $aliases = [
            'fecha' => 'fecha',
            'tipo' => 'tipo',
            'producto' => 'producto',
            'ubicacion_stock' => 'ubicacion_stock',
            'unidad_base' => 'unidad_base',
        ];
        foreach ($query->sorts as $sort) {
            if ($grouped && ! in_array($sort['field'], [...$query->groupings, ...$query->metrics], true)) {
                continue;
            }
            $builder->orderBy($aliases[$sort['field']], $sort['direction']);
        }

        return $builder->orderBy($grouped ? $query->groupings[0] ?? $query->metrics[0] ?? 'fecha' : 'fecha');
    }

    /** @param list<string> $columns @return array<string, mixed> */
    private function rowToArray(object $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;
            if (in_array($column, ['variacion_fisica', 'variacion_reservada', 'cantidad_ingresos', 'cantidad_salidas', 'cantidad_perdidas', 'cantidad_ajustes', 'cantidad_reservada'], true)) {
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
            if (in_array($column, ['variacion_fisica', 'variacion_reservada', 'cantidad_ingresos', 'cantidad_salidas', 'cantidad_perdidas', 'cantidad_ajustes', 'cantidad_reservada'], true)) {
                $units[$column] = 'unidad_base';
            }
        }

        return $units;
    }
}
