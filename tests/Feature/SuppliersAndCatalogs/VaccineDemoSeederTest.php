<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuppliersAndCatalogs\VaccineDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VaccineDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: integra la ficha sobre VAC-001 y conserva sus existencias demo al repetir la carga.
    public function test_local_demo_adopts_existing_vaccine_product_idempotently(): void
    {
        // Preparación: habilita el entorno local y aísla el almacenamiento de las otras demos.
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Acción: ejecuta la entrada oficial y conserva la ficha e inventario de VAC-001.
        $this->seed(DatabaseSeeder::class);
        $product = Product::query()->where('sku', 'VAC-001')->firstOrFail();
        $before = Vaccine::query()->where('product_id', $product->id)->sole()->getAttributes();
        $inventoryBefore = $this->inventorySnapshot($product->id);

        // Acción: repite el seeder del módulo en el mismo entorno local.
        $this->seed(VaccineDemoSeeder::class);

        // Verificación: no crea ficha ni movimiento y conserva los saldos y líneas históricas.
        $this->assertSame($before, Vaccine::query()->where('product_id', $product->id)->sole()->getAttributes());
        $this->assertSame($inventoryBefore, $this->inventorySnapshot($product->id));
        $this->assertDatabaseCount('vaccines', 1);
    }

    // Flujo: respeta una ficha previa de VAC-001 creada con una clave de idempotencia distinta.
    public function test_local_demo_does_not_mutate_an_existing_vaccine_with_another_key(): void
    {
        // Preparación: crea la ficha, producto y saldo previos en el entorno local.
        $this->app->instance('env', 'local');
        Storage::fake('local');
        $product = Product::factory()->vaccine()->create(['sku' => 'VAC-001', 'name' => 'Vacuna previa']);
        $vaccine = Vaccine::factory()->create(['product_id' => $product->id, 'idempotency_key' => '11111111-1111-4111-8111-111111111111']);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'on_hand_quantity' => '4.000000']);
        $movement = InventoryMovement::factory()->create();
        InventoryMovementLine::factory()->create([
            'inventory_movement_id' => $movement->id,
            'product_id' => $product->id,
            'stock_location_id' => $balance->stock_location_id,
            'unit' => $product->base_unit->value,
            'on_hand_delta' => '4.000000',
        ]);
        $before = [$product->fresh()->getAttributes(), $vaccine->fresh()->getAttributes(), $this->inventorySnapshot($product->id)];

        // Acción: ejecuta la demo con la ficha ya asociada a ese SKU.
        $this->seed(VaccineDemoSeeder::class);

        // Verificación: conserva ficha, producto y registros de inventario sin reescritura.
        $this->assertSame($before, [$product->fresh()->getAttributes(), $vaccine->fresh()->getAttributes(), $this->inventorySnapshot($product->id)]);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $vaccine->fresh()->idempotency_key);
        $this->assertDatabaseCount('vaccines', 1);
    }

    // Flujo: omite la demo de vacunas en testing para evitar datos ficticios fuera de local.
    public function test_vaccine_demo_does_not_run_outside_local(): void
    {
        // Preparación: conserva el entorno de pruebas normal.
        $this->app->instance('env', 'testing');

        // Acción: invoca directamente el seeder protegido.
        $this->seed(VaccineDemoSeeder::class);

        // Verificación: no persiste vacunas ni productos ficticios.
        $this->assertDatabaseCount('vaccines', 0);
        $this->assertDatabaseCount('products', 0);
    }

    /** @return array{balances: list<array<string, mixed>>, movement_lines: list<array<string, mixed>>, movements: list<array<string, mixed>>} */
    private function inventorySnapshot(int $productId): array
    {
        $lines = InventoryMovementLine::query()->where('product_id', $productId)->orderBy('id')->get();
        $movementIds = $lines->pluck('inventory_movement_id')->all();

        return [
            'balances' => StockBalance::query()->where('product_id', $productId)->orderBy('id')->get()->map(static fn (StockBalance $balance): array => $balance->getAttributes())->all(),
            'movement_lines' => $lines->map(static fn (InventoryMovementLine $line): array => $line->getAttributes())->all(),
            'movements' => InventoryMovement::query()->whereIn('id', $movementIds)->orderBy('id')->get()->map(static fn (InventoryMovement $movement): array => $movement->getAttributes())->all(),
        ];
    }
}
