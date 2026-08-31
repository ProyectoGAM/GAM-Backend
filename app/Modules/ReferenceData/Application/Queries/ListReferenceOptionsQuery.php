<?php

namespace App\Modules\ReferenceData\Application\Queries;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Enums\StockLocationStatus;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use BackedEnum;

final readonly class ListReferenceOptionsQuery
{
    /** @return array<string, mixed> */
    public function execute(User $actor): array
    {
        $options = [
            'departamentos' => [],
            'localidades' => [],
            'unidades_productivas' => [],
            'galpones' => [],
            'proveedores' => [],
            'productos' => [],
            'ubicaciones_stock' => [],
            'saldos_inventario' => [],
            'movimientos' => [],
            'tipos' => [
                'productos' => $this->enumOptions(ProductKind::cases(), [
                    'raw_material' => 'Materia prima',
                    'supply' => 'Suministro',
                    'finished_feed' => 'Alimento terminado',
                    'egg' => 'Huevo',
                    'medicine' => 'Medicamento',
                    'vaccine' => 'Vacuna',
                    'other' => 'Otro',
                ]),
                'unidades_base' => $this->enumOptions(BaseUnit::cases(), [
                    'unit' => 'Unidad',
                    'kg' => 'Kilogramo',
                    'g' => 'Gramo',
                    'l' => 'Litro',
                    'ml' => 'Mililitro',
                    'dose' => 'Dosis',
                ]),
                'movimientos' => $this->enumOptions(InventoryMovementType::cases(), [
                    'opening_balance' => 'Saldo inicial',
                    'receipt' => 'Ingreso',
                    'issue' => 'Salida',
                    'loss' => 'Pérdida',
                    'adjustment' => 'Ajuste',
                    'transfer' => 'Transferencia',
                    'reversal' => 'Reversión',
                ]),
                'booleanos' => [
                    $this->option('true', 'Sí'),
                    $this->option('false', 'No'),
                ],
            ],
            'estados' => [
                'unidades_productivas' => $this->enumOptions(ProductionUnitStatus::cases(), [
                    'active' => 'Activo',
                    'inactive' => 'Inactivo',
                ]),
                'galpones' => $this->enumOptions(PoultryHouseStatus::cases(), [
                    'operational' => 'Operativo',
                    'maintenance' => 'Mantenimiento',
                    'out_of_service' => 'Fuera de servicio',
                    'inactive' => 'Inactivo',
                ]),
                'proveedores' => $this->enumOptions(SupplierStatus::cases(), [
                    'active' => 'Activo',
                    'inactive' => 'Inactivo',
                ]),
                'productos' => $this->enumOptions(ProductStatus::cases(), [
                    'active' => 'Activo',
                    'inactive' => 'Inactivo',
                ]),
                'ubicaciones_stock' => $this->enumOptions(StockLocationStatus::cases(), [
                    'active' => 'Activo',
                    'inactive' => 'Inactivo',
                ]),
            ],
            'auditoria' => [
                'eventos' => [],
                'origenes' => [],
            ],
        ];

        if ($this->allowedAny($actor, ['geography.view', 'geography.manage'])) {
            $options['departamentos'] = $this->departments();
            $options['localidades'] = $this->localities();
        }

        if ($this->allowedAny($actor, ['production-units.view', 'production-units.manage', 'inventory.manage'])) {
            $options['unidades_productivas'] = $this->productionUnits();
        }

        if ($this->allowedAny($actor, ['poultry-houses.view', 'poultry-houses.manage'])) {
            $options['galpones'] = $this->poultryHouses();
        }

        if ($this->allowedAny($actor, ['suppliers.view', 'suppliers.manage', 'inventory.move'])) {
            $options['proveedores'] = $this->suppliers();
        }

        if ($this->allowedAny($actor, ['products.view', 'products.manage', 'inventory.move', 'inventory.adjust'])) {
            $options['productos'] = $this->products();
        }

        if ($this->allowedAny($actor, ['inventory.view', 'inventory.move', 'inventory.adjust', 'inventory.manage'])) {
            $options['ubicaciones_stock'] = $this->stockLocations();
            $options['saldos_inventario'] = $this->stockBalances();
            $options['movimientos'] = $this->movements();
        }

        if ($this->allowed($actor, 'audit.view')) {
            $options['auditoria'] = [
                'eventos' => $this->distinctAuditOptions('event'),
                'origenes' => $this->distinctAuditOptions('source'),
            ];
        }

        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function departments(): array
    {
        return Department::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => $this->option($department->getKey(), $department->name))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function localities(): array
    {
        return Locality::query()
            ->with('department:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'department_id', 'name'])
            ->map(fn (Locality $locality): array => $this->option(
                $locality->getKey(),
                $locality->name.' — '.$locality->department->name,
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function productionUnits(): array
    {
        return ProductionUnit::query()
            ->with('locality:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'locality_id', 'name'])
            ->map(fn (ProductionUnit $unit): array => $this->option(
                $unit->getKey(),
                $unit->name.' — '.$unit->locality->name,
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function poultryHouses(): array
    {
        return PoultryHouse::query()
            ->with('productionUnit:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'production_unit_id', 'name'])
            ->map(fn (PoultryHouse $house): array => $this->option(
                $house->getKey(),
                $house->name.' — '.$house->productionUnit->name,
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function suppliers(): array
    {
        return Supplier::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier): array => $this->option($supplier->getKey(), $supplier->name))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function products(): array
    {
        return Product::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'sku', 'name'])
            ->map(fn (Product $product): array => $this->option(
                $product->getKey(),
                $product->sku.' — '.$product->name,
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function stockLocations(): array
    {
        return StockLocation::query()
            ->with('productionUnit:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'production_unit_id', 'name'])
            ->map(fn (StockLocation $location): array => $this->option(
                $location->getKey(),
                $location->productionUnit === null
                    ? $location->name
                    : $location->name.' — '.$location->productionUnit->name,
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function stockBalances(): array
    {
        return StockBalance::query()
            ->with(['product:id,sku,name', 'stockLocation:id,name'])
            ->orderBy('id')
            ->get(['id', 'product_id', 'stock_location_id'])
            ->map(fn (StockBalance $balance): array => $this->option(
                $balance->getKey(),
                $balance->product->sku.' — '.$balance->product->name.' · '.$balance->stockLocation->name,
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function movements(): array
    {
        return InventoryMovement::query()
            ->whereNull('reverses_movement_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get(['id', 'type', 'occurred_at'])
            ->map(fn (InventoryMovement $movement): array => $this->option(
                $movement->getKey(),
                sprintf(
                    'Movimiento #%d · %s · %s',
                    $movement->getKey(),
                    $movement->type->value,
                    $movement->occurred_at->format('d/m/Y H:i'),
                ),
            ))
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function distinctAuditOptions(string $column): array
    {
        return AuditEntry::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn (mixed $value): array => $this->option((string) $value, (string) $value))
            ->values()
            ->all();
    }

    /** @param list<BackedEnum> $cases @param array<string, string> $labels */
    private function enumOptions(array $cases, array $labels): array
    {
        return array_map(
            fn (BackedEnum $case): array => $this->option($case->value, $labels[$case->value] ?? $case->name),
            $cases,
        );
    }

    /** @return array{value: int|string, label: string} */
    private function option(int|string $value, string $label): array
    {
        return ['value' => $value, 'label' => $label];
    }

    private function allowed(User $actor, string $permission): bool
    {
        return $actor->hasRole('admin') || $actor->checkPermissionTo($permission);
    }

    /** @param list<string> $permissions */
    private function allowedAny(User $actor, array $permissions): bool
    {
        return $actor->hasRole('admin') || $actor->getAllPermissions()->pluck('name')->intersect($permissions)->isNotEmpty();
    }
}
