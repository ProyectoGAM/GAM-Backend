<?php

namespace Database\Seeders\Inventory;

use App\Actions\FarmStructure\CreatePoultryHouseAction;
use App\Actions\Inventory\RecordInventoryMovementAction;
use App\Actions\Inventory\SetMinimumStockAction;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Inventory\StockLocationStatus;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class FeedStockDemoSeeder extends Seeder
{
    public function run(
        CreatePoultryHouseAction $createPoultryHouse,
        RecordInventoryMovementAction $recordMovement,
        SetMinimumStockAction $setMinimumStock,
    ): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $units = ProductionUnit::query()
            ->whereIn('normalized_name', ['granja el ombú', 'granja santa clara'])
            ->get()
            ->keyBy('normalized_name');

        $plants = [];
        foreach ([
            ['unit' => 'granja el ombú', 'name' => 'Planta de ración El Ombú'],
            ['unit' => 'granja santa clara', 'name' => 'Planta de ración Santa Clara'],
        ] as $definition) {
            $unit = $units->get($definition['unit']);
            if ($unit === null) {
                throw new \RuntimeException('No se encontró la unidad productiva demo de la planta de ración.');
            }

            $plant = PoultryHouse::query()
                ->where('production_unit_id', $unit->getKey())
                ->where('normalized_name', Str::lower($definition['name']))
                ->first();

            if ($plant === null) {
                $plant = $createPoultryHouse->execute($unit, [
                    'name' => $definition['name'],
                    'type' => PoultryHouseType::Feed->value,
                ], $actor);
            }

            if ($plant->type !== PoultryHouseType::Feed) {
                throw new \RuntimeException('La planta demo existente no tiene tipo feed.');
            }

            $plants[$definition['unit']] = $plant;
        }

        $locations = [];
        foreach ($plants as $unit => $plant) {
            $location = StockLocation::query()
                ->where('poultry_house_id', $plant->getKey())
                ->sole();

            if ($location->status !== StockLocationStatus::Active) {
                throw new \RuntimeException('La ubicación de la planta demo no está activa.');
            }

            $locations[$unit] = $location;
        }

        $products = Product::query()
            ->whereIn('sku', ['MAIZ-025', 'SOJA-025'])
            ->where('kind', ProductKind::RawMaterial->value)
            ->get()
            ->keyBy('sku')
            ->all();
        $supplier = Supplier::query()->where('normalized_name', 'agroinsumos del sur')->firstOrFail();

        $this->seedMovements($recordMovement, $actor, $products, $locations, $supplier);
        $this->seedMinimums($setMinimumStock, $actor, $products, $locations);
    }

    /**
     * Registra los movimientos de materias primas que explican los saldos demo en gramos.
     *
     * @param  array<string, Product>  $products
     * @param  array<string, StockLocation>  $locations
     */
    private function seedMovements(
        RecordInventoryMovementAction $recordMovement,
        User $actor,
        array $products,
        array $locations,
        Supplier $supplier,
    ): void
    {
        $this->recordMovement(
            $recordMovement,
            '00000000-0000-4000-8000-000000000001',
            InventoryMovementType::OpeningBalance,
            null,
            'Carga inicial de materias primas de las plantas de ración.',
            Carbon::create(2026, 8, 26, 12, 0, 0, 'UTC'),
            [
                $this->movementLine($products['MAIZ-025'], $locations['granja el ombú'], '1000000.000000'),
                $this->movementLine($products['SOJA-025'], $locations['granja el ombú'], '800000.000000'),
                $this->movementLine($products['MAIZ-025'], $locations['granja santa clara'], '700000.000000'),
                $this->movementLine($products['SOJA-025'], $locations['granja santa clara'], '400000.000000'),
            ],
            $actor,
        );

        $this->recordMovement(
            $recordMovement,
            '00000000-0000-4000-8000-000000000002',
            InventoryMovementType::Receipt,
            $supplier->getKey(),
            'Recepción demo de materias primas para las plantas de ración.',
            Carbon::create(2026, 9, 10, 12, 0, 0, 'UTC'),
            [
                $this->movementLine($products['MAIZ-025'], $locations['granja el ombú'], '500000.000000'),
                $this->movementLine($products['SOJA-025'], $locations['granja el ombú'], '200000.000000'),
                $this->movementLine($products['MAIZ-025'], $locations['granja santa clara'], '200000.000000'),
                $this->movementLine($products['SOJA-025'], $locations['granja santa clara'], '100000.000000'),
            ],
            $actor,
        );

        $this->recordMovement(
            $recordMovement,
            '00000000-0000-4000-8000-000000000003',
            InventoryMovementType::Issue,
            null,
            'Consumo demo de materias primas para alimentación.',
            Carbon::create(2026, 9, 18, 12, 0, 0, 'UTC'),
            [
                $this->movementLine($products['MAIZ-025'], $locations['granja el ombú'], '-260000.000000'),
                $this->movementLine($products['SOJA-025'], $locations['granja el ombú'], '-320000.000000'),
                $this->movementLine($products['MAIZ-025'], $locations['granja santa clara'], '-140000.000000'),
                $this->movementLine($products['SOJA-025'], $locations['granja santa clara'], '-80000.000000'),
            ],
            $actor,
        );
    }

    /** @param array<string, Product> $products @param array<string, StockLocation> $locations */
    private function seedMinimums(SetMinimumStockAction $setMinimumStock, User $actor, array $products, array $locations): void
    {
        $minimums = [
            ['product' => 'MAIZ-025', 'location' => 'granja el ombú', 'minimum' => '500000.000000'],
            ['product' => 'SOJA-025', 'location' => 'granja el ombú', 'minimum' => '300000.000000'],
            ['product' => 'MAIZ-025', 'location' => 'granja santa clara', 'minimum' => '400000.000000'],
            ['product' => 'SOJA-025', 'location' => 'granja santa clara', 'minimum' => '250000.000000'],
        ];

        foreach ($minimums as $definition) {
            $balance = StockBalance::query()->where([
                'product_id' => $products[$definition['product']]->getKey(),
                'stock_location_id' => $locations[$definition['location']]->getKey(),
            ])->firstOrFail();
            $minimum = $definition['minimum'];

            if ((string) $balance->minimum_quantity !== '0.000000' && (string) $balance->minimum_quantity !== $minimum) {
                continue;
            }
            if ((string) $balance->minimum_quantity === $minimum) {
                continue;
            }

            $setMinimumStock->execute($balance, $minimum, $actor);
        }
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
    private function recordMovement(
        RecordInventoryMovementAction $recordMovement,
        string $operationId,
        InventoryMovementType $type,
        ?int $supplierId,
        string $reason,
        Carbon $occurredAt,
        array $lines,
        User $actor,
    ): void {
        $recordMovement->execute(new InventoryMovementCommand(
            type: $type,
            lines: $lines,
            operationId: $operationId,
            supplierId: $supplierId,
            reason: $reason,
            occurredAt: $occurredAt->toIso8601String(),
        ), $actor, 'seeder');
    }
}
