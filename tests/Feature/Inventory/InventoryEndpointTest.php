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

    // Flujo: ingresa y retira stock; verifica saldo, ledger y auditoría.
    public function test_receipt_and_issue_update_the_projection_and_ledger(): void
    {
        [$actor, $producto, $location, $proveedor] = $this->inventoryScenario();
        $receiveKey = (string) Str::uuid();

        // Acción 1: registra el ingreso de 10,5 unidades al almacén.
        $this->postJson('/api/v1/inventario/ingresos', [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '10.500000']],
        ], ['Idempotency-Key' => $receiveKey])->assertCreated();

        // Acción 2: registra la salida de 2,5 unidades.
        $this->postJson('/api/v1/inventario/salidas', [
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '2.500000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Verificación: confirma saldo proyectado, movimientos y auditoría.
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $producto->getKey(),
            'stock_location_id' => $location->getKey(),
            'on_hand_quantity' => '8.000000',
        ]);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('activity_log', ['event' => 'inventory_movement_recorded', 'causer_id' => $actor->getKey()]);
    }

    // Flujo: repite el mismo ingreso; verifica que la idempotencia evita duplicados.
    public function test_replaying_the_same_idempotency_key_does_not_duplicate_stock(): void
    {
        [, $producto, $location, $proveedor] = $this->inventoryScenario();
        $key = (string) Str::uuid();
        $payload = [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '4.000000']],
        ];

        // Acción 1: procesa el ingreso y guarda su resultado idempotente.
        $first = $this->postJson('/api/v1/inventario/ingresos', $payload, ['Idempotency-Key' => $key])->assertCreated();

        // Acción 2: repite exactamente la misma solicitud.
        $second = $this->postJson('/api/v1/inventario/ingresos', $payload, ['Idempotency-Key' => $key])->assertCreated();

        // Verificación: confirma replay del mismo movimiento sin duplicar efectos.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '4.000000']);
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: reutiliza la clave con otro payload; verifica el conflicto sin cambios extra.
    public function test_reusing_an_idempotency_key_with_different_payload_returns_conflict(): void
    {
        [, $producto, $location, $proveedor] = $this->inventoryScenario();
        $key = (string) Str::uuid();
        $basePayload = [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '4.000000']],
        ];

        // Acción 1: procesa el payload original con la clave.
        $this->postJson('/api/v1/inventario/ingresos', $basePayload, ['Idempotency-Key' => $key])->assertCreated();

        // Acción 2: reutiliza la clave con una cantidad distinta.
        $this->postJson('/api/v1/inventario/ingresos', [
            ...$basePayload,
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '5.000000']],
        ], ['Idempotency-Key' => $key])->assertConflict();

        // Verificación: confirma conflicto y conserva el primer saldo.
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '4.000000']);
    }

    // Flujo: intenta retirar más stock del disponible; verifica conflicto y rollback.
    public function test_issue_with_insufficient_stock_returns_conflict_without_a_movement(): void
    {
        [, $producto, $location] = $this->inventoryScenario();

        // Acción: intenta retirar una cantidad superior al stock disponible.
        $this->postJson('/api/v1/inventario/salidas', [
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '1.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        // Verificación: confirma que el conflicto no crea movimientos.
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_movement_lines', 0);
    }

    // Flujo: recibe, reserva y consume stock; verifica la reducción física y reservada.
    public function test_reservation_reduces_available_stock_and_consumption_reduces_physical_stock(): void
    {
        [, $producto, $location, $proveedor] = $this->inventoryScenario();

        // Acción 1: recibe stock físico para habilitar la reserva.
        $this->postJson('/api/v1/inventario/ingresos', [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '10.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 2: reserva tres unidades y conserva el stock físico.
        $reserva = $this->postJson('/api/v1/inventario/reservas', [
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '3.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reservationLineId = $reserva->json('data.lineas.0.id');

        // Verificación intermedia: confirma el aumento del stock reservado.
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '10.000000', 'reserved_quantity' => '3.000000']);

        // Acción 3: consume la reserva y reduce el stock físico.
        $this->postJson('/api/v1/inventario/reservas/'.$reserva->json('data.id').'/consumos', [
            'lineas' => [['linea_reserva_id' => $reservationLineId, 'cantidad' => '3.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        // Verificación final: confirma el consumo y la liberación de la reserva.
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '7.000000', 'reserved_quantity' => '0.000000']);
    }

    // Flujo: transfiere, ajusta y revierte stock; verifica la proyección final por ubicación.
    public function test_transfer_adjustment_and_reversal_keep_the_projection_consistent(): void
    {
        [, $producto, $origin, $proveedor] = $this->inventoryScenario();

        // Preparación: crea la ubicación destino para la transferencia.
        $destination = StockLocation::factory()->create();

        // Acción 1: recibe diez unidades en la ubicación de origen.
        $this->postJson('/api/v1/inventario/ingresos', [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $origin->getKey(), 'cantidad' => '10.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 2: transfiere tres unidades a la ubicación destino.
        $transfer = $this->postJson('/api/v1/inventario/transferencias', [
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_origen_id' => $origin->getKey(), 'ubicacion_stock_destino_id' => $destination->getKey(), 'cantidad' => '3.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Verificación intermedia: confirma la distribución tras la transferencia.
        $this->assertDatabaseHas('stock_balances', ['product_id' => $producto->getKey(), 'stock_location_id' => $origin->getKey(), 'on_hand_quantity' => '7.000000']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $producto->getKey(), 'stock_location_id' => $destination->getKey(), 'on_hand_quantity' => '3.000000']);

        // Acción 3: ajusta el saldo de origen al conteo físico.
        $this->postJson('/api/v1/inventario/ajustes', [
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $origin->getKey(), 'cantidad_contada' => '6.000000']],
            'motivo' => 'Conteo físico',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Verificación intermedia: confirma el conteo ajustado en origen.
        $this->assertDatabaseHas('stock_balances', ['product_id' => $producto->getKey(), 'stock_location_id' => $origin->getKey(), 'on_hand_quantity' => '6.000000']);

        // Acción 4: revierte la transferencia original.
        $this->postJson('/api/v1/inventario/movimientos/'.$transfer->json('data.id').'/reversiones', [
            'motivo' => 'Corrección de transferencia',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Verificación final: confirma que la reversión conserva una proyección coherente.
        $this->assertDatabaseHas('stock_balances', ['product_id' => $producto->getKey(), 'stock_location_id' => $origin->getKey(), 'on_hand_quantity' => '9.000000']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $producto->getKey(), 'stock_location_id' => $destination->getKey(), 'on_hand_quantity' => '0.000000']);
    }

    // Flujo: recibe, reserva y libera stock; verifica que vuelve a estar disponible.
    public function test_releasing_a_reservation_restores_available_stock(): void
    {
        [, $producto, $location, $proveedor] = $this->inventoryScenario();

        // Acción 1: recibe stock físico para reservarlo después.
        $this->postJson('/api/v1/inventario/ingresos', [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '5.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 2: reserva dos unidades del saldo disponible.
        $reserva = $this->postJson('/api/v1/inventario/reservas', [
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '2.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 3: libera completamente la reserva.
        $this->postJson('/api/v1/inventario/reservas/'.$reserva->json('data.id').'/liberaciones', [
            'lineas' => [['linea_reserva_id' => $reserva->json('data.lineas.0.id'), 'cantidad' => '2.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        // Verificación: confirma que el stock reservado vuelve a estar disponible.
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '5.000000', 'reserved_quantity' => '0.000000']);
    }

    // Flujo: intenta fraccionar un producto por unidad; verifica rechazo sin movimiento.
    public function test_fractional_quantity_is_rejected_for_unit_productos(): void
    {
        [, $producto, $location, $proveedor] = $this->inventoryScenario();

        // Acción 1: configura el producto como una unidad indivisible.
        $producto->update(['base_unit' => BaseUnit::Unit]);

        // Acción 2: intenta ingresar una cantidad fraccionaria.
        $this->postJson('/api/v1/inventario/ingresos', [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '1.500000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        // Verificación: confirma que la validación no crea movimientos.
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: recibe stock y actualiza el mínimo; verifica el cambio mediante la política.
    public function test_stock_minimum_is_updated_through_the_balance_policy(): void
    {
        [, $producto, $location, $proveedor] = $this->inventoryScenario();

        // Acción 1: recibe stock para crear el saldo administrable.
        $this->postJson('/api/v1/inventario/ingresos', [
            'proveedor_id' => $proveedor->getKey(),
            'lineas' => [['producto_id' => $producto->getKey(), 'ubicacion_stock_id' => $location->getKey(), 'cantidad' => '5.000000']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 2: consulta el saldo creado.
        $balance = StockBalance::query()->firstOrFail();

        // Acción 3: actualiza el stock mínimo mediante su endpoint de política.
        $this->patchJson('/api/v1/inventario/saldos/'.$balance->getKey().'/stock-minimo', [
            'cantidad_minima' => '6.000000',
        ])->assertOk();

        // Verificación: confirma el nuevo mínimo persistido.
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
