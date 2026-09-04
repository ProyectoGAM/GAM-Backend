<?php

namespace Database\Seeders;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Enums\StockLocationStatus;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportExportWriter;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportQueryNormalizer;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use Database\Seeders\FarmStructure\MaintenanceDemoSeeder;
use Database\Seeders\Lots\EggProductionDemoSeeder;
use Database\Seeders\Lots\LotsDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;

final class LocalDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $localities = $this->seedLocalities();
        $units = $this->seedProductionUnits($localities);
        $this->seedPoultryHouses($units);
        $this->call(MaintenanceDemoSeeder::class);
        $suppliers = $this->seedSuppliers($localities);
        $products = $this->seedProducts();
        $locations = $this->seedStockLocations($units);

        $this->seedBalances($products, $locations);
        $this->seedMovements($admin, $products, $locations, $suppliers);
        $this->seedReports($admin);
        $this->call(LotsDemoSeeder::class);
        $this->call(EggProductionDemoSeeder::class);
    }

    /** @return array<string, Locality> */
    private function seedLocalities(): array
    {
        $localities = [];
        foreach ([
            'Las Piedras' => 'Canelones',
            'Libertad' => 'San José',
            'Melilla' => 'Montevideo',
        ] as $name => $departmentName) {
            $department = Department::query()->firstOrCreate(['name' => $departmentName]);
            $localities[$name] = Locality::query()->firstOrCreate([
                'department_id' => $department->getKey(),
                'name' => $name,
            ]);
        }

        return $localities;
    }

    /** @param array<string, Locality> $localities @return array<string, ProductionUnit> */
    private function seedProductionUnits(array $localities): array
    {
        $definitions = [
            'Granja El Ombú' => ['locality' => 'Las Piedras', 'latitude' => '-34.729000', 'longitude' => '-56.219000'],
            'Granja Santa Clara' => ['locality' => 'Libertad', 'latitude' => '-34.634000', 'longitude' => '-56.618000'],
        ];
        $units = [];

        foreach ($definitions as $name => $definition) {
            $unit = ProductionUnit::query()->firstOrNew([
                'locality_id' => $localities[$definition['locality']]->getKey(),
                'normalized_name' => Str::lower($name),
            ]);
            $unit->forceFill([
                'locality_id' => $localities[$definition['locality']]->getKey(),
                'name' => $name,
                'latitude' => $definition['latitude'],
                'longitude' => $definition['longitude'],
                'status' => ProductionUnitStatus::Active,
            ])->save();
            $units[$name] = $unit;
        }

        return $units;
    }

    /** @param array<string, ProductionUnit> $units */
    private function seedPoultryHouses(array $units): void
    {
        $definitions = [
            ['unit' => 'Granja El Ombú', 'name' => 'Galpón Norte', 'capacity' => 6000, 'status' => PoultryHouseStatus::Operational],
            ['unit' => 'Granja El Ombú', 'name' => 'Galpón Sur', 'capacity' => 4500, 'status' => PoultryHouseStatus::Maintenance],
            ['unit' => 'Granja Santa Clara', 'name' => 'Galpón Ponedoras', 'capacity' => 8000, 'status' => PoultryHouseStatus::Operational],
        ];

        foreach ($definitions as $definition) {
            $house = PoultryHouse::query()->firstOrNew([
                'production_unit_id' => $units[$definition['unit']]->getKey(),
                'normalized_name' => Str::lower($definition['name']),
            ]);
            $house->forceFill([
                'production_unit_id' => $units[$definition['unit']]->getKey(),
                'name' => $definition['name'],
                'bird_capacity' => $definition['capacity'],
                'status' => $definition['status'],
            ])->save();
        }
    }

    /** @param array<string, Locality> $localities @return array<string, Supplier> */
    private function seedSuppliers(array $localities): array
    {
        $definitions = [
            'Agroinsumos del Sur' => ['locality' => 'Las Piedras', 'address' => 'Ruta 5 km 24, Canelones'],
            'NutriAves Uruguay' => ['locality' => 'Melilla', 'address' => 'Camino de los Aromos 1450, Montevideo'],
        ];
        $suppliers = [];

        foreach ($definitions as $name => $definition) {
            $supplier = Supplier::query()->firstOrNew(['normalized_name' => Str::lower($name)]);
            $supplier->forceFill([
                'locality_id' => $localities[$definition['locality']]->getKey(),
                'name' => $name,
                'address' => $definition['address'],
                'status' => SupplierStatus::Active,
            ])->save();
            $suppliers[$name] = $supplier;
        }

        return $suppliers;
    }

    /** @return array<string, Product> */
    private function seedProducts(): array
    {
        $definitions = [
            'corn' => ['sku' => 'MAIZ-025', 'name' => 'Maíz en grano', 'kind' => ProductKind::RawMaterial, 'unit' => BaseUnit::Kilogram],
            'soy' => ['sku' => 'SOJA-025', 'name' => 'Harina de soja', 'kind' => ProductKind::RawMaterial, 'unit' => BaseUnit::Kilogram],
            'vaccine' => ['sku' => 'VAC-001', 'name' => 'Vacuna aviar Newcastle', 'kind' => ProductKind::Vaccine, 'unit' => BaseUnit::Dose],
            'disinfectant' => ['sku' => 'DESINF-005', 'name' => 'Desinfectante concentrado', 'kind' => ProductKind::Supply, 'unit' => BaseUnit::Liter],
        ];
        $products = [];

        foreach ($definitions as $key => $definition) {
            $product = Product::query()->firstOrNew(['sku' => $definition['sku']]);
            $product->forceFill([
                'sku' => $definition['sku'],
                'name' => $definition['name'],
                'kind' => $definition['kind'],
                'base_unit' => $definition['unit'],
                'stock_tracked' => true,
                'status' => ProductStatus::Active,
            ])->save();
            $products[$key] = $product;
        }

        return $products;
    }

    /** @param array<string, ProductionUnit> $units @return array<string, StockLocation> */
    private function seedStockLocations(array $units): array
    {
        $definitions = [
            'ombu_feed' => ['name' => 'Depósito de alimentos - El Ombú', 'unit' => 'Granja El Ombú'],
            'ombu_supplies' => ['name' => 'Cámara de insumos - El Ombú', 'unit' => 'Granja El Ombú'],
            'santa_clara_feed' => ['name' => 'Depósito de alimentos - Santa Clara', 'unit' => 'Granja Santa Clara'],
        ];
        $locations = [];

        foreach ($definitions as $key => $definition) {
            $location = StockLocation::query()->firstOrNew(['normalized_name' => Str::lower($definition['name'])]);
            $location->forceFill([
                'production_unit_id' => $units[$definition['unit']]->getKey(),
                'name' => $definition['name'],
                'status' => StockLocationStatus::Active,
            ])->save();
            $locations[$key] = $location;
        }

        return $locations;
    }

    /** @param array<string, Product> $products @param array<string, StockLocation> $locations */
    private function seedBalances(array $products, array $locations): void
    {
        $balances = [
            ['product' => 'corn', 'location' => 'ombu_feed', 'on_hand' => '1240.000000', 'minimum' => '500.000000'],
            ['product' => 'soy', 'location' => 'ombu_feed', 'on_hand' => '680.000000', 'minimum' => '300.000000'],
            ['product' => 'vaccine', 'location' => 'ombu_supplies', 'on_hand' => '120.000000', 'minimum' => '50.000000'],
            ['product' => 'disinfectant', 'location' => 'ombu_supplies', 'on_hand' => '85.500000', 'minimum' => '30.000000'],
            ['product' => 'corn', 'location' => 'santa_clara_feed', 'on_hand' => '760.000000', 'minimum' => '400.000000'],
            ['product' => 'soy', 'location' => 'santa_clara_feed', 'on_hand' => '420.000000', 'minimum' => '250.000000'],
        ];

        foreach ($balances as $definition) {
            $balance = StockBalance::query()->firstOrNew([
                'product_id' => $products[$definition['product']]->getKey(),
                'stock_location_id' => $locations[$definition['location']]->getKey(),
            ]);
            $balance->forceFill([
                'on_hand_quantity' => $definition['on_hand'],
                'minimum_quantity' => $definition['minimum'],
            ])->save();
        }
    }

    /**
     * Registra movimientos históricos que explican los saldos demo actuales.
     *
     * @param  array<string, Product>  $products
     * @param  array<string, StockLocation>  $locations
     * @param  array<string, Supplier>  $suppliers
     */
    private function seedMovements(User $admin, array $products, array $locations, array $suppliers): void
    {
        $this->saveMovement(
            '00000000-0000-4000-8000-000000000001',
            InventoryMovementType::OpeningBalance,
            null,
            'Carga inicial de existencias al comenzar la temporada.',
            now()->subDays(30),
            [
                $this->movementLine($products['corn'], $locations['ombu_feed'], '1000.000000'),
                $this->movementLine($products['soy'], $locations['ombu_feed'], '800.000000'),
                $this->movementLine($products['vaccine'], $locations['ombu_supplies'], '150.000000'),
                $this->movementLine($products['disinfectant'], $locations['ombu_supplies'], '100.000000'),
                $this->movementLine($products['corn'], $locations['santa_clara_feed'], '700.000000'),
                $this->movementLine($products['soy'], $locations['santa_clara_feed'], '400.000000'),
            ],
            $admin,
        );

        $this->saveMovement(
            '00000000-0000-4000-8000-000000000002',
            InventoryMovementType::Receipt,
            $suppliers['Agroinsumos del Sur']->getKey(),
            'Recepción de materias primas y alimento para las dos granjas.',
            now()->subDays(15),
            [
                $this->movementLine($products['corn'], $locations['ombu_feed'], '500.000000'),
                $this->movementLine($products['soy'], $locations['ombu_feed'], '200.000000'),
                $this->movementLine($products['corn'], $locations['santa_clara_feed'], '200.000000'),
                $this->movementLine($products['soy'], $locations['santa_clara_feed'], '100.000000'),
            ],
            $admin,
        );

        $this->saveMovement(
            '00000000-0000-4000-8000-000000000003',
            InventoryMovementType::Issue,
            null,
            'Consumo semanal para alimentación, vacunación y limpieza.',
            now()->subDays(7),
            [
                $this->movementLine($products['corn'], $locations['ombu_feed'], '-260.000000'),
                $this->movementLine($products['soy'], $locations['ombu_feed'], '-320.000000'),
                $this->movementLine($products['vaccine'], $locations['ombu_supplies'], '-30.000000'),
                $this->movementLine($products['disinfectant'], $locations['ombu_supplies'], '-14.500000'),
                $this->movementLine($products['corn'], $locations['santa_clara_feed'], '-140.000000'),
                $this->movementLine($products['soy'], $locations['santa_clara_feed'], '-80.000000'),
            ],
            $admin,
        );

    }

    private function seedReports(User $admin): void
    {
        $sourceKey = 'inventario.saldos-stock';
        $query = app(ReportQueryNormalizer::class)->normalize($sourceKey, [
            'columnas' => ['producto', 'unidad_base', 'ubicacion_stock', 'cantidad_disponible', 'cantidad_minima'],
            'filtros' => [],
            'desde' => null,
            'hasta' => null,
            'ordenamientos' => [['campo' => 'producto', 'direccion' => 'asc']],
            'agrupaciones' => [],
            'metricas' => [],
            'pagina' => 1,
            'por_pagina' => 50,
        ]);
        $configuration = $query->toArray();

        $preset = ReportPreset::query()->firstOrNew([
            'user_id' => $admin->getKey(),
            'normalized_name' => Str::lower('Stock disponible por ubicación'),
        ]);
        $preset->forceFill([
            'name' => 'Stock disponible por ubicación',
            'source_key' => $sourceKey,
            'definition_version' => $query->definitionVersion,
            'configuration' => $configuration,
        ])->save();

        try {
            $payloadHash = hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new \RuntimeException('No fue posible preparar la exportación demo.', previous: $exception);
        }

        $export = ReportExport::query()->firstOrNew([
            'user_id' => $admin->getKey(),
            'idempotency_key_hash' => hash('sha256', $admin->getKey().'|local-demo-saldos-2026'),
        ]);
        $export->forceFill([
            'operation_id' => '00000000-0000-4000-8000-000000000006',
            'payload_hash' => $payloadHash,
            'source_key' => $sourceKey,
            'definition_version' => $query->definitionVersion,
            'query' => $configuration,
            'format' => ReportExportFormat::Xlsx,
            'status' => ReportExportStatus::Pending,
            'disk' => 'local',
            'path' => null,
            'file_name' => 'saldos-de-inventario-demo.xlsx',
            'mime_type' => null,
            'file_size' => null,
            'expires_at' => now()->addDays(30),
            'completed_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $source = app(ReportSourceRegistry::class)->get($sourceKey);
        $file = app(ReportExportWriter::class)->write($export, $source, $query);
        $export->forceFill([
            ...$file,
            'status' => ReportExportStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    /** @return array{product_id: int, stock_location_id: int, on_hand_delta: string, unit: string} */
    private function movementLine(Product $product, StockLocation $location, string $onHand): array
    {
        return [
            'product_id' => $product->getKey(),
            'stock_location_id' => $location->getKey(),
            'on_hand_delta' => $onHand,
            'unit' => $product->base_unit->value,
        ];
    }

    /**
     * @param  list<array{product_id: int, stock_location_id: int, on_hand_delta: string, unit: string}>  $lines
     */
    private function saveMovement(
        string $operationId,
        InventoryMovementType $type,
        ?int $supplierId,
        string $reason,
        Carbon $occurredAt,
        array $lines,
        User $admin,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): void {
        $movement = InventoryMovement::query()->firstOrNew(['operation_id' => $operationId]);
        $movement->forceFill([
            'operation_id' => $operationId,
            'request_hash' => hash('sha256', 'local-demo|'.$operationId),
            'type' => $type,
            'supplier_id' => $supplierId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
            'created_by' => $admin->getKey(),
            'created_at' => $occurredAt,
        ])->save();

        foreach ($lines as $definition) {
            $line = InventoryMovementLine::query()->firstOrNew([
                'inventory_movement_id' => $movement->getKey(),
                'product_id' => $definition['product_id'],
                'stock_location_id' => $definition['stock_location_id'],
            ]);
            $line->forceFill([
                'unit' => $definition['unit'],
                'on_hand_delta' => $definition['on_hand_delta'],
            ])->save();
        }
    }
}
