<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class InventoryEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_receipt_and_issue_update_the_projection_and_ledger(): void
    {
        [$actor, $product, $location, $supplier] = $this->inventoryScenario();
        $receiveKey = (string) Str::uuid();
        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '10.500000']],
        ], ['Idempotency-Key' => $receiveKey])->assertCreated();

        $this->postJson('/api/v1/inventario/salidas', [
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '2.500000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->getKey(),
            'stock_location_id' => $location->getKey(),
            'on_hand_quantity' => '8.000000',
        ]);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('activity_log', ['event' => 'inventory_movement_recorded', 'causer_id' => $actor->getKey()]);
    }

    public function test_replaying_the_same_idempotency_key_does_not_duplicate_stock(): void
    {
        [, $product, $location, $supplier] = $this->inventoryScenario();
        $key = (string) Str::uuid();
        $payload = [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '4.000000']],
        ];

        $first = $this->postJson('/api/v1/inventario/ingresos', $payload, ['Idempotency-Key' => $key])->assertCreated();
        $second = $this->postJson('/api/v1/inventario/ingresos', $payload, ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '4.000000']);
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_reusing_an_idempotency_key_with_different_payload_returns_conflict(): void
    {
        [, $product, $location, $supplier] = $this->inventoryScenario();
        $key = (string) Str::uuid();
        $basePayload = [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '4.000000']],
        ];

        $this->postJson('/api/v1/inventario/ingresos', $basePayload, ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/v1/inventario/ingresos', [
            ...$basePayload,
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '5.000000']],
        ], ['Idempotency-Key' => $key])->assertConflict();

        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '4.000000']);
    }

    public function test_issue_with_insufficient_stock_returns_conflict_without_a_movement(): void
    {
        [, $product, $location] = $this->inventoryScenario();

        $this->postJson('/api/v1/inventario/salidas', [
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '1.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_movement_lines', 0);
    }

    public function test_reservation_reduces_available_stock_and_consumption_reduces_physical_stock(): void
    {
        [, $product, $location, $supplier] = $this->inventoryScenario();
        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '10.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $reservation = $this->postJson('/api/v1/inventario/reservas', [
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '3.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reservationLineId = $reservation->json('data.lines.0.id');

        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '10.000000', 'reserved_quantity' => '3.000000']);
        $this->postJson('/api/v1/inventario/reservas/'.$reservation->json('data.id').'/consumos', [
            'lines' => [['reservation_line_id' => $reservationLineId, 'quantity' => '3.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '7.000000', 'reserved_quantity' => '0.000000']);
    }

    public function test_transfer_adjustment_and_reversal_keep_the_projection_consistent(): void
    {
        [, $product, $origin, $supplier] = $this->inventoryScenario();
        $destination = StockLocation::factory()->create();
        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $origin->getKey(), 'quantity' => '10.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $transfer = $this->postJson('/api/v1/inventario/transferencias', [
            'lines' => [['product_id' => $product->getKey(), 'from_stock_location_id' => $origin->getKey(), 'to_stock_location_id' => $destination->getKey(), 'quantity' => '3.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->getKey(), 'stock_location_id' => $origin->getKey(), 'on_hand_quantity' => '7.000000']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->getKey(), 'stock_location_id' => $destination->getKey(), 'on_hand_quantity' => '3.000000']);

        $this->postJson('/api/v1/inventario/ajustes', [
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $origin->getKey(), 'counted_quantity' => '6.000000']],
            'reason' => 'Conteo físico',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->getKey(), 'stock_location_id' => $origin->getKey(), 'on_hand_quantity' => '6.000000']);

        $this->postJson('/api/v1/inventario/movimientos/'.$transfer->json('data.id').'/reversiones', [
            'reason' => 'Corrección de transferencia',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->getKey(), 'stock_location_id' => $origin->getKey(), 'on_hand_quantity' => '9.000000']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->getKey(), 'stock_location_id' => $destination->getKey(), 'on_hand_quantity' => '0.000000']);
    }

    public function test_releasing_a_reservation_restores_available_stock(): void
    {
        [, $product, $location, $supplier] = $this->inventoryScenario();
        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '5.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reservation = $this->postJson('/api/v1/inventario/reservas', [
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '2.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->postJson('/api/v1/inventario/reservas/'.$reservation->json('data.id').'/liberaciones', [
            'lines' => [['reservation_line_id' => $reservation->json('data.lines.0.id'), 'quantity' => '2.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '5.000000', 'reserved_quantity' => '0.000000']);
    }

    public function test_fractional_quantity_is_rejected_for_unit_products(): void
    {
        [, $product, $location, $supplier] = $this->inventoryScenario();
        $product->update(['base_unit' => BaseUnit::Unit]);

        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '1.500000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_stock_minimum_is_updated_through_the_balance_policy(): void
    {
        [, $product, $location, $supplier] = $this->inventoryScenario();
        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => $supplier->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey(), 'quantity' => '5.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $balance = StockBalance::query()->firstOrFail();
        $this->patchJson('/api/v1/inventario/saldos/'.$balance->getKey().'/stock-minimo', [
            'minimum_quantity' => '6.000000',
        ])->assertOk();

        $this->assertDatabaseHas('stock_balances', ['id' => $balance->getKey(), 'minimum_quantity' => '6.000000']);
    }

    /** @return array{0:User, 1:Product, 2:StockLocation, 3:Supplier} */
    private function inventoryScenario(): array
    {
        $actor = User::factory()->create();
        foreach (['inventory.view', 'inventory.move', 'inventory.adjust', 'inventory.reserve', 'inventory.manage'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($actor, ['*']);

        return [$actor, Product::factory()->create(), StockLocation::factory()->create(), Supplier::factory()->create()];
    }
}
