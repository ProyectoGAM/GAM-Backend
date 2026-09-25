<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateFeedStockLocationAction;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FeedStockEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea una planta, registra una materia prima en kg y verifica la conversión e idempotencia.
    public function test_creates_feed_ingredient_in_grams_and_replays_equivalent_units(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view', 'inventory.manage']);
        $house = $this->feedHouse($actor);
        $key = (string) Str::uuid();

        // Acción 1: registra la ficha y la carga inicial de un ingrediente en kilogramos.
        $first = $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'MAIZ-FEED-001',
            'nombre' => 'Maíz para ración',
            'cantidad' => '1.5',
            'unidad' => 'kg',
        ], ['Idempotency-Key' => $key])->assertCreated();

        // Acción 2: repite la misma operación en gramos equivalentes.
        $second = $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'MAIZ-FEED-001',
            'nombre' => 'Maíz para ración',
            'cantidad' => '1500',
            'unidad' => 'g',
        ], ['Idempotency-Key' => $key])->assertCreated();

        // Verificación: confirma un único producto, movimiento y saldo canónico en gramos.
        $this->assertSame($first->json('data.movement.id'), $second->json('data.movement.id'));
        $this->assertSame('g', $first->json('data.product.base_unit'));
        $this->assertSame('1500.000000', $first->json('data.stock.available_quantity'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '1500.000000', 'allow_negative' => true]);
    }

    // Flujo: rechaza reutilizar una clave de idempotencia con un payload diferente.
    public function test_rejects_same_idempotency_key_with_different_payload(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view', 'inventory.manage']);
        $house = $this->feedHouse($actor);
        $key = (string) Str::uuid();

        // Acción 1: registra la primera carga con la clave compartida.
        $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'TRIGO-FEED-001',
            'nombre' => 'Trigo para ración',
            'cantidad' => '100',
            'unidad' => 'g',
        ], ['Idempotency-Key' => $key])->assertCreated();

        // Acción 2: intenta reutilizar la clave con una cantidad diferente.
        $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'TRIGO-FEED-001',
            'nombre' => 'Trigo para ración',
            'cantidad' => '200',
            'unidad' => 'g',
        ], ['Idempotency-Key' => $key])->assertConflict();

        // Verificación: conserva el único movimiento y el saldo de la primera carga.
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '100.000000']);
    }

    // Flujo: conserva el replay de un movimiento histórico cuyo hash no tenía escala decimal.
    public function test_replays_legacy_movement_hash_without_unit_or_scale(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view', 'inventory.manage']);
        $house = $this->feedHouse($actor);

        // Preparación: crea la materia prima y recupera su ubicación técnica.
        $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'CEBADA-FEED-001',
            'nombre' => 'Cebada para ración',
            'cantidad' => '100',
            'unidad' => 'g',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $product = Product::query()->where('sku', 'CEBADA-FEED-001')->firstOrFail();
        $location = StockLocation::query()->where('poultry_house_id', $house->getKey())->firstOrFail();
        $operationId = (string) Str::uuid();
        $command = new InventoryMovementCommand(
            type: InventoryMovementType::Issue,
            lines: [[
                'product_id' => (int) $product->getKey(),
                'stock_location_id' => (int) $location->getKey(),
                'on_hand_delta' => '-25',
            ]],
            operationId: $operationId,
        );

        // Acción 1: registra un movimiento HTTP sin unidad y sustituye su hash por el formato histórico.
        $response = $this->postJson('/api/v1/inventory/issues', [
            'lines' => [[
                'product_id' => $product->getKey(),
                'stock_location_id' => $location->getKey(),
                'quantity' => '25',
            ]],
        ], ['Idempotency-Key' => $operationId])->assertCreated();
        $movement = InventoryMovement::query()->findOrFail($response->json('data.id'));
        $legacyHash = $command->requestHash();
        InventoryMovement::query()->whereKey($movement->getKey())->update(['request_hash' => $legacyHash]);

        // Acción 2: repite exactamente la solicitud histórica por HTTP.
        $replay = $this->postJson('/api/v1/inventory/issues', [
            'lines' => [[
                'product_id' => $product->getKey(),
                'stock_location_id' => $location->getKey(),
                'quantity' => '25',
            ]],
        ], ['Idempotency-Key' => $operationId])->assertCreated();

        // Verificación: devuelve el mismo movimiento sin volver a afectar el saldo.
        $this->assertSame($movement->getKey(), $replay->json('data.id'));
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '75.000000']);
    }

    // Flujo: cuenta plantas operativas y en mantenimiento, y permite movimientos en todos sus estados.
    public function test_feed_stock_remains_movable_until_inactive_and_excludes_only_inactive_totals(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view', 'inventory.manage', 'poultry-houses.manage']);
        $house = $this->feedHouse($actor);

        // Preparación: registra el ingrediente con saldo inicial.
        $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'AVENA-FEED-001',
            'nombre' => 'Avena para ración',
            'cantidad' => '100',
            'unidad' => 'g',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $product = Product::query()->where('sku', 'AVENA-FEED-001')->firstOrFail();
        $location = StockLocation::query()->where('poultry_house_id', $house->getKey())->firstOrFail();

        // Acción 1: cambia a mantenimiento y mueve stock; el total sigue incluido.
        $this->patchJson('/api/v1/poultry-houses/'.$house->getKey().'/status', ['status' => 'maintenance'])->assertOk();
        $this->issueFeedGrams($product->getKey(), $location->getKey(), '10');
        $this->getJson('/api/v1/stock-alimentacion')->assertJsonPath('data.items.0.total_g', '90.000000');

        // Acción 2: cambia a fuera de servicio y mueve stock; el total sigue incluido.
        $this->patchJson('/api/v1/poultry-houses/'.$house->getKey().'/status', ['status' => 'out_of_service'])->assertOk();
        $this->issueFeedGrams($product->getKey(), $location->getKey(), '10');
        $this->getJson('/api/v1/stock-alimentacion')->assertJsonPath('data.items.0.total_g', '80.000000');

        // Acción 3: cambia a inactiva, mueve stock y excluye sólo este detalle del total.
        $this->patchJson('/api/v1/poultry-houses/'.$house->getKey().'/status', ['status' => 'inactive'])->assertOk();
        $this->issueFeedGrams($product->getKey(), $location->getKey(), '10');
        $this->getJson('/api/v1/plantas-racion/'.$house->getKey().'/stock')
            ->assertJsonPath('data.items.0.details.0.stock_g', '70.000000')
            ->assertJsonPath('data.items.0.details.0.included_in_totals', false);
        $this->getJson('/api/v1/stock-alimentacion')->assertJsonPath('data.items.0.total_g', '0.000000');
    }

    // Flujo: exige permisos de inventario y rechaza galpones que no sean plantas de ración.
    public function test_feed_endpoints_enforce_permissions_and_house_type(): void
    {
        // Preparación: crea una planta y un galpón avícola sin permisos funcionales.
        $actor = $this->signIn([]);
        $feedHouse = $this->feedHouse($actor);
        $poultryHouse = PoultryHouse::factory()->create();

        // Acción 1: intenta consultar el stock sin permiso de inventario.
        $this->getJson('/api/v1/stock-alimentacion')->assertForbidden();

        // Acción 2: intenta registrar un ingrediente en un galpón avícola con los permisos de escritura.
        $actor->givePermissionTo([
            Permission::findOrCreate('products.manage', 'web'),
            Permission::findOrCreate('inventory.move', 'web'),
        ]);
        Sanctum::actingAs($actor, ['*']);
        $this->postJson('/api/v1/plantas-racion/'.$poultryHouse->getKey().'/ingredientes', [
            'sku' => 'AVICOLA-001',
            'nombre' => 'Ingrediente inválido',
            'cantidad' => '1',
            'unidad' => 'kg',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        // Verificación: la planta válida permanece sin movimientos por este intento.
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('stock_locations', ['poultry_house_id' => $feedHouse->getKey()]);
    }

    // Flujo: crea un ingrediente con proveedor y conserva el tipo de recepción.
    public function test_feed_ingredient_with_supplier_creates_receipt(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view']);
        $house = $this->feedHouse($actor);
        $supplier = Supplier::factory()->create();

        // Acción: registra la primera carga indicando proveedor.
        $response = $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'SORGO-FEED-001',
            'nombre' => 'Sorgo para ración',
            'cantidad' => '2',
            'unidad' => 'kg',
            'proveedor_id' => $supplier->getKey(),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Verificación: la operación es una recepción asociada al proveedor.
        $response->assertJsonPath('data.movement.type', InventoryMovementType::Receipt->value)
            ->assertJsonPath('data.movement.supplier.id', $supplier->getKey());
    }

    // Flujo: convierte líneas mixtas antes de fusionarlas en una transferencia.
    public function test_transfer_merges_mixed_kg_and_gram_lines_exactly(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view', 'inventory.manage']);
        $sourceHouse = $this->feedHouse($actor);
        $targetHouse = $this->feedHouse($actor);

        // Preparación: carga 1.5 kg en la ubicación de origen.
        $this->postJson('/api/v1/plantas-racion/'.$sourceHouse->getKey().'/ingredientes', [
            'sku' => 'MIX-FEED-001',
            'nombre' => 'Mezcla para ración',
            'cantidad' => '1.5',
            'unidad' => 'kg',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $product = Product::query()->where('sku', 'MIX-FEED-001')->firstOrFail();
        $sourceLocation = StockLocation::query()->where('poultry_house_id', $sourceHouse->getKey())->firstOrFail();
        $targetLocation = StockLocation::query()->where('poultry_house_id', $targetHouse->getKey())->firstOrFail();

        // Acción: transfiere dos líneas equivalentes con unidades distintas.
        $this->postJson('/api/v1/inventory/transfers', [
            'lines' => [
                [
                    'product_id' => $product->getKey(),
                    'from_stock_location_id' => $sourceLocation->getKey(),
                    'to_stock_location_id' => $targetLocation->getKey(),
                    'quantity' => '1',
                    'unit' => 'kg',
                ],
                [
                    'product_id' => $product->getKey(),
                    'from_stock_location_id' => $sourceLocation->getKey(),
                    'to_stock_location_id' => $targetLocation->getKey(),
                    'quantity' => '500',
                    'unit' => 'g',
                ],
            ],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Verificación: el origen queda en cero y el destino recibe 1.5 kg exactos.
        $this->getJson('/api/v1/plantas-racion/'.$sourceHouse->getKey().'/stock')
            ->assertJsonPath('data.items.0.total_g', '0.000000');
        $this->getJson('/api/v1/plantas-racion/'.$targetHouse->getKey().'/stock')
            ->assertJsonPath('data.items.0.total_g', '1500.000000');
    }

    // Flujo: permite una salida que cruza cero, audita el negativo y excluye sólo la planta inactiva de los totales.
    public function test_negative_feed_stock_is_audited_and_excluded_when_the_plant_is_inactive(): void
    {
        $actor = $this->signIn(['products.manage', 'inventory.move', 'inventory.view', 'inventory.manage', 'poultry-houses.manage']);
        $house = $this->feedHouse($actor);

        // Acción 1: carga el ingrediente que será retirado.
        $this->postJson('/api/v1/plantas-racion/'.$house->getKey().'/ingredientes', [
            'sku' => 'SOJA-FEED-001',
            'nombre' => 'Soja para ración',
            'cantidad' => '500',
            'unidad' => 'g',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $product = Product::query()->where('sku', 'SOJA-FEED-001')->firstOrFail();
        $location = StockLocation::query()->where('poultry_house_id', $house->getKey())->firstOrFail();

        // Acción 2: retira más gramos de los disponibles desde el movimiento genérico.
        $issue = $this->postJson('/api/v1/inventory/issues', [
            'lines' => [[
                'product_id' => $product->getKey(),
                'stock_location_id' => $location->getKey(),
                'quantity' => '750',
                'unit' => 'g',
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 3: marca inactiva la planta para consultar su detalle histórico.
        $this->patchJson('/api/v1/poultry-houses/'.$house->getKey().'/status', ['status' => PoultryHouseStatus::Inactive->value])
            ->assertOk();

        // Acción 4: mueve stock una vez más sin generar una segunda alerta.
        $this->issueFeedGrams($product->getKey(), $location->getKey(), '100');

        // Consulta: verifica el saldo local y el total global excluyendo la planta inactiva.
        $this->getJson('/api/v1/plantas-racion/'.$house->getKey().'/stock')
            ->assertOk()
            ->assertJsonPath('data.items.0.total_g', '0.000000')
            ->assertJsonPath('data.items.0.details.0.stock_g', '-350.000000')
            ->assertJsonPath('data.items.0.details.0.is_negative', true)
            ->assertJsonPath('data.items.0.details.0.included_in_totals', false);
        $this->getJson('/api/v1/stock-alimentacion')
            ->assertOk()
            ->assertJsonPath('data.items.0.total_g', '0.000000');

        // Verificación: confirma una sola auditoría con operación y cantidades del primer cruce.
        $negativeAudit = AuditEntry::query()->where('event', 'inventory_stock_became_negative')->firstOrFail();
        $this->assertSame($issue->json('data.operation_id'), $negativeAudit->operation_id);
        $this->assertSame('500.000000', $negativeAudit->properties->get('previous_quantity'));
        $this->assertSame('-250.000000', $negativeAudit->properties->get('resulting_quantity'));
        $this->assertSame(1, DB::table('activity_log')->where('event', 'inventory_stock_became_negative')->count());
    }

    // Flujo: bloquea el movimiento de materias primas fuera de plantas de ración.
    public function test_raw_material_movement_outside_feed_location_returns_conflict(): void
    {
        $this->signIn(['products.manage', 'inventory.move', 'inventory.view']);
        $product = Product::factory()->create([
            'kind' => 'raw_material',
            'base_unit' => 'g',
            'stock_tracked' => true,
        ]);
        $location = StockLocation::factory()->create();

        // Acción: intenta ingresar la materia prima en una ubicación no vinculada a una planta.
        $this->postJson('/api/v1/inventory/receipts', [
            'supplier_id' => Supplier::factory()->create()->getKey(),
            'lines' => [[
                'product_id' => $product->getKey(),
                'stock_location_id' => $location->getKey(),
                'quantity' => '100',
                'unit' => 'g',
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        // Verificación: confirma que el rechazo no creó saldo ni movimiento.
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: protege la ubicación técnica de edición y cambios de estado independientes.
    public function test_feed_location_cannot_be_updated_or_deactivated_independently(): void
    {
        $actor = $this->signIn(['inventory.view', 'inventory.manage']);
        $house = $this->feedHouse($actor);
        $location = StockLocation::query()->where('poultry_house_id', $house->getKey())->firstOrFail();

        // Acción 1: intenta renombrar la ubicación técnica.
        $this->patchJson('/api/v1/stock-locations/'.$location->getKey(), ['name' => 'Ubicación modificada'])
            ->assertConflict();

        // Acción 2: intenta cambiar su estado desde Inventario.
        $this->patchJson('/api/v1/stock-locations/'.$location->getKey().'/status', ['status' => 'inactive'])
            ->assertConflict();

        // Verificación: conserva nombre, estado y vínculo técnico.
        $this->assertDatabaseHas('stock_locations', [
            'id' => $location->getKey(),
            'poultry_house_id' => $house->getKey(),
            'status' => 'active',
            'system_managed' => true,
        ]);
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function feedHouse(User $actor): PoultryHouse
    {
        $unit = ProductionUnit::factory()->create();
        $house = PoultryHouse::factory()->for($unit)->feed()->create();
        app(CreateFeedStockLocationAction::class)->execute($house, $actor);

        return $house;
    }

    private function issueFeedGrams(int|string $productId, int|string $locationId, string $quantity): void
    {
        $this->postJson('/api/v1/inventory/issues', [
            'lines' => [[
                'product_id' => $productId,
                'stock_location_id' => $locationId,
                'quantity' => $quantity,
                'unit' => BaseUnit::Gram->value,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }
}
