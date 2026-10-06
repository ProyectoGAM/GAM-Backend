<?php

namespace Tests\Feature\Deliveries;

use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\IdentityAndAccess\PinHasher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class DeliveryEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        config(['identity.pin.pepper' => 'test-only-temporary-value']);
        $user = User::factory()->create([
            'pin_hash' => app(PinHasher::class)->hash('0007'),
            'pin_enabled' => true,
            'pin_pepper_version' => config('identity.pin.pepper_version'),
            'pin_daily_window_started_at' => now(),
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function command(string $method, string $path, array $payload, ?string $key = null): TestResponse
    {
        if ($method === 'POST' && ($path === '/repartos' || str_ends_with($path, '/cierre'))) {
            $payload = ['pin' => '0007', ...$payload];
        }

        return $this->json($method, '/api/v1'.$path, $payload, [
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    public function test_start_and_close_require_the_drivers_valid_pin_without_persisting_it(): void
    {
        $actor = $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $start = ['production_unit_id' => $unit->id, 'quantity' => 10];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson('/api/v1/repartos', $start, $headers)->assertUnprocessable();
        $this->command('POST', '/repartos', [...$start, 'pin' => '9999'])->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_PIN');
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'pin_failed_attempts' => 1]);

        $key = (string) Str::uuid();
        $deliveryId = $this->command('POST', '/repartos', $start, $key)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'pin_failed_attempts' => 0]);
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'start_request_hash' => hash('sha256', json_encode(['idempotency_key' => $key, ...$start])),
        ]);

        $close = ['returned_quantity' => 10];
        $this->postJson("/api/v1/repartos/{$deliveryId}/cierre", $close, $headers)->assertUnprocessable();
        $this->command('POST', "/repartos/{$deliveryId}/cierre", [...$close, 'pin' => '9999'])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_PIN');
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'active']);
        $this->command('POST', "/repartos/{$deliveryId}/cierre", $close)->assertOk();
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'completed']);
    }

    public function test_delivery_pin_must_be_configured_and_is_locked_after_repeated_failures(): void
    {
        $actor = $this->signIn(['delivery.start']);
        $actor->forceFill(['pin_enabled' => false, 'pin_hash' => null])->save();
        $this->command('POST', '/repartos', ['quantity' => 10])->assertStatus(422)
            ->assertJsonPath('code', 'PIN_NOT_CONFIGURED');

        $actor->forceFill([
            'pin_enabled' => true,
            'pin_hash' => app(PinHasher::class)->hash('0007'),
        ])->save();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->command('POST', '/repartos', ['quantity' => 10, 'pin' => '9999'])
                ->assertUnauthorized()->assertJsonPath('code', 'INVALID_PIN');
        }
        $this->command('POST', '/repartos', ['quantity' => 10, 'pin' => '9999'])
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->command('POST', '/repartos', ['quantity' => 10])
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_delivery_role_can_start_without_preassigned_clients_and_replay_without_duplicate_load(): void
    {
        // Preparación: autentica un repartidor y crea una unidad productiva operativa.
        $actor = $this->signIn(['delivery.start', 'delivery.view-own']);
        $unit = ProductionUnit::factory()->create();
        $key = (string) Str::uuid();
        $payload = ['production_unit_id' => $unit->id, 'quantity' => 120];

        // Request: inicia el reparto con una carga de huevos.
        $created = $this->command('POST', '/repartos', $payload, $key)->assertCreated();

        // Verificación: el repartidor decide a quién visitar; no se crean pendientes.
        $created->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.loaded_quantity', 120)
            ->assertJsonCount(0, 'data.stops');
        $deliveryId = $created->json('data.id');
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'driver_id' => $actor->id,
            'status' => 'active',
            'loaded_quantity' => 120,
        ]);
        $this->assertDatabaseCount('delivery_loads', 1);
        $this->assertDatabaseCount('delivery_stops', 0);
        $this->assertDatabaseHas('egg_stock_transactions', [
            'reference_type' => 'delivery',
            'reference_id' => $deliveryId,
            'type' => 'distribution_preparation',
            'quantity' => 120,
        ]);

        // Request: repite exactamente la misma operación offline al recuperar la conexión.
        $this->command('POST', '/repartos', $payload, $key)->assertCreated()->assertJsonPath('data.id', $deliveryId);

        // Verificación: la repetición idempotente no crea otra carga ni otra preparación de stock.
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_loads', 1);
        $this->assertDatabaseCount('delivery_stops', 0);
        $this->assertDatabaseCount('egg_stock_transactions', 1);
    }

    public function test_driver_chooses_clients_in_any_order_and_can_add_an_idempotent_mid_route_load(): void
    {
        $actor = $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $deliveryId = $this->command('POST', '/repartos', [
            'production_unit_id' => $unit->id, 'quantity' => 60,
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/repartos/clientes?limit=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'demo-001');
        $this->getJson('/api/v1/repartos/clientes?search=&limit=50')->assertOk()
            ->assertJsonCount(6, 'data');
        $this->getJson('/api/v1/repartos/clientes?search=Estación')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'demo-006');

        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-006', 'status' => 'delivered',
            'items' => [['unit' => 'huevo', 'amount' => '40', 'eggs_per_unit' => 1]],
        ])->assertOk()->assertJsonPath('data.client_reference', 'demo-006');

        $key = (string) Str::uuid();
        $this->command('POST', "/repartos/{$deliveryId}/cargas", ['quantity' => 30], $key)
            ->assertOk()->assertJsonPath('data.loaded_quantity', 90)
            ->assertJsonPath('data.remaining_quantity', 50)
            ->assertJsonCount(2, 'data.loads');
        $this->command('POST', "/repartos/{$deliveryId}/cargas", ['quantity' => 30], $key)
            ->assertOk()->assertJsonPath('data.loaded_quantity', 90);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", ['quantity' => 31], $key)->assertStatus(409);

        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-001', 'status' => 'delivered',
            'items' => [['unit' => 'huevo', 'amount' => '20', 'eggs_per_unit' => 1]],
        ])->assertOk();
        $this->assertDatabaseHas('delivery_stops', [
            'client_reference' => 'demo-006', 'sequence' => 1, 'delivery_id' => Delivery::query()->where('public_id', $deliveryId)->firstOrFail()->id,
        ]);
        $this->assertDatabaseHas('delivery_stops', [
            'client_reference' => 'demo-001', 'sequence' => 2,
        ]);
        $this->assertDatabaseHas('delivery_loads', [
            'delivery_id' => Delivery::query()->where('public_id', $deliveryId)->firstOrFail()->id,
            'quantity' => 30, 'type' => 'additional', 'created_by' => $actor->id,
        ]);
        $this->assertDatabaseCount('delivery_loads', 2);
        $this->assertDatabaseHas('egg_stock_transactions', [
            'reference_id' => $deliveryId, 'type' => 'distribution_preparation',
            'quantity' => 30,
        ]);
        $this->assertDatabaseCount('egg_stock_transactions', 2);
        $this->assertDatabaseCount('delivery_stops', 2);

        $this->command('POST', "/repartos/{$deliveryId}/cierre", ['returned_quantity' => 30])
            ->assertOk()
            ->assertJsonPath('data.loaded_quantity', 90)
            ->assertJsonPath('data.delivered_quantity', 60)
            ->assertJsonPath('data.returned_quantity', 30);
        $this->assertDatabaseHas('egg_stock_transactions', [
            'reference_id' => $deliveryId, 'type' => 'distribution_return', 'quantity' => 30,
        ]);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", ['quantity' => 30], $key)
            ->assertOk()->assertJsonPath('data.loaded_quantity', 90);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", ['quantity' => 1])->assertStatus(409);
    }

    public function test_unknown_client_and_foreign_driver_cannot_modify_a_delivery(): void
    {
        $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $deliveryId = $this->command('POST', '/repartos', [
            'production_unit_id' => $unit->id, 'quantity' => 10,
        ])->assertCreated()->json('data.id');

        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'unknown', 'status' => 'delivered',
            'items' => [['unit' => 'huevo', 'amount' => '1', 'eggs_per_unit' => 1]],
        ])->assertStatus(409);
        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-001', 'status' => 'pending',
        ])->assertUnprocessable();

        $other = User::factory()->create();
        $other->givePermissionTo(Permission::findOrCreate('delivery.update-own', 'web'));
        Sanctum::actingAs($other, ['api:access']);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", ['quantity' => 10])->assertForbidden();
        $this->assertDatabaseCount('delivery_stops', 0);
        $this->assertDatabaseCount('delivery_loads', 1);
    }

    public function test_visit_without_delivery_records_zero_eggs_without_items(): void
    {
        $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $deliveryId = $this->command('POST', '/repartos', [
            'production_unit_id' => $unit->id, 'quantity' => 10,
        ])->assertCreated()->json('data.id');

        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-001', 'status' => 'not_delivered',
            'visit_reason' => 'Negocio cerrado',
        ])->assertOk()
            ->assertJsonPath('data.delivered_quantity', 0)
            ->assertJsonPath('data.items', []);
        $this->getJson("/api/v1/repartos/{$deliveryId}")->assertOk()
            ->assertJsonPath('data.remaining_quantity', 10)
            ->assertJsonPath('data.unit_balances.rows.0.remaining_amount', '10');
    }

    public function test_mixed_and_fractional_loads_keep_their_conversion_snapshot(): void
    {
        $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $this->getJson('/api/v1/repartos/unidades')->assertOk()
            ->assertJsonPath('data.1.id', 'maple')
            ->assertJsonPath('data.1.eggs_per_unit', 30);

        $initial = ['production_unit_id' => $unit->id, 'items' => [
            ['unit' => 'maple', 'amount' => '0.5', 'eggs_per_unit' => 30],
            ['unit' => 'huevo', 'amount' => '5', 'eggs_per_unit' => 1],
        ]];
        $started = $this->command('POST', '/repartos', $initial)->assertCreated()
            ->assertJsonPath('data.loaded_quantity', 20)
            ->assertJsonPath('data.loads.0.items.0.eggs', 15)
            ->assertJsonPath('data.loads.0.items.1.eggs', 5);
        $deliveryId = $started->json('data.id');

        $additional = ['items' => [
            ['unit' => 'cajon_6', 'amount' => '1', 'eggs_per_unit' => 180],
            ['unit' => 'maple', 'amount' => '0.5', 'eggs_per_unit' => 30],
        ]];
        $key = (string) Str::uuid();
        $this->command('POST', "/repartos/{$deliveryId}/cargas", $additional, $key)->assertOk()
            ->assertJsonPath('data.loaded_quantity', 215)
            ->assertJsonPath('data.loads.1.quantity', 195)
            ->assertJsonPath('data.loads.1.items.1.eggs', 15);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", $additional, $key)->assertOk()
            ->assertJsonPath('data.loaded_quantity', 215);
        $changed = $additional;
        $changed['items'][0]['amount'] = '0.5';
        $this->command('POST', "/repartos/{$deliveryId}/cargas", $changed, $key)->assertStatus(409);

        $this->command('POST', "/repartos/{$deliveryId}/cargas", [
            'items' => [['unit' => 'maple', 'amount' => '0.1', 'eggs_per_unit' => 30]],
        ])->assertOk()->assertJsonPath('data.loaded_quantity', 218);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", [
            'items' => [['unit' => 'huevo', 'amount' => '0.5', 'eggs_per_unit' => 1]],
        ])->assertUnprocessable();
        $this->command('POST', "/repartos/{$deliveryId}/cargas", [
            'items' => [['unit' => 'maple', 'amount' => '1', 'eggs_per_unit' => 29]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_loads', 3);
        $this->assertDatabaseHas('egg_stock_transactions', [
            'reference_id' => $deliveryId, 'quantity' => 195, 'type' => 'distribution_preparation',
        ]);

        // Las cargas previas no se recalculan si el catálogo cambia más adelante.
        config()->set('delivery_units.maple.eggs_per_unit', 24);
        $this->getJson("/api/v1/repartos/{$deliveryId}")->assertOk()
            ->assertJsonPath('data.loads.0.items.0.eggs_per_unit', 30)
            ->assertJsonPath('data.loads.0.items.0.eggs', 15);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", $additional, $key)->assertOk()
            ->assertJsonPath('data.loaded_quantity', 218);
        $this->command('POST', "/repartos/{$deliveryId}/cargas", [
            'items' => [['unit' => 'maple', 'amount' => '1', 'eggs_per_unit' => 30]],
        ])->assertUnprocessable();
    }

    public function test_loads_created_before_the_items_migration_still_read_as_eggs(): void
    {
        $this->signIn(['delivery.start', 'delivery.view-own']);
        $unit = ProductionUnit::factory()->create();
        $deliveryId = $this->command('POST', '/repartos', [
            'production_unit_id' => $unit->id, 'quantity' => 12,
        ])->assertCreated()->json('data.id');
        Delivery::query()->where('public_id', $deliveryId)->firstOrFail()
            ->loads()->firstOrFail()->forceFill(['items' => null, 'request_hash' => null])->save();

        $this->getJson("/api/v1/repartos/{$deliveryId}")->assertOk()
            ->assertJsonPath('data.loads.0.quantity', 12)
            ->assertJsonPath('data.loads.0.items.0.unit', 'huevo')
            ->assertJsonPath('data.loads.0.items.0.eggs', 12);
    }

    public function test_delivery_items_decrement_only_their_real_loaded_presentation_and_replay_safely(): void
    {
        $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $deliveryId = $this->command('POST', '/repartos', [
            'production_unit_id' => $unit->id,
            'items' => [
                ['unit' => 'huevo', 'amount' => '5', 'eggs_per_unit' => 1],
                ['unit' => 'maple', 'amount' => '2', 'eggs_per_unit' => 30],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.unit_balances.rows.0.loaded_amount', '5')
            ->assertJsonPath('data.unit_balances.rows.1.loaded_amount', '2')
            ->json('data.id');

        $key = (string) Str::uuid();
        $first = ['client_reference' => 'demo-001', 'status' => 'delivered', 'items' => [
            ['unit' => 'maple', 'amount' => '1', 'eggs_per_unit' => 30],
            ['unit' => 'huevo', 'amount' => '3', 'eggs_per_unit' => 1],
        ]];
        $this->command('POST', "/repartos/{$deliveryId}/entregas", $first, $key)->assertOk()
            ->assertJsonPath('data.delivered_quantity', 33)
            ->assertJsonPath('data.items.0.eggs', 30);
        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-002', 'status' => 'delivered', 'delivered_quantity' => 1,
        ])->assertUnprocessable();
        $this->command('POST', "/repartos/{$deliveryId}/entregas", $first, $key)->assertOk();
        $this->assertDatabaseCount('delivery_stops', 1);
        $this->getJson("/api/v1/repartos/{$deliveryId}")->assertOk()
            ->assertJsonPath('data.delivered_quantity', 33)
            ->assertJsonPath('data.remaining_quantity', 32)
            ->assertJsonPath('data.unit_balances.rows.0.delivered_amount', '3')
            ->assertJsonPath('data.unit_balances.rows.0.remaining_amount', '2')
            ->assertJsonPath('data.unit_balances.rows.1.delivered_amount', '1')
            ->assertJsonPath('data.unit_balances.rows.1.remaining_amount', '1');

        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-002', 'status' => 'delivered',
            'items' => [['unit' => 'maple', 'amount' => '2', 'eggs_per_unit' => 30]],
        ])->assertUnprocessable();
        $this->command('POST', "/repartos/{$deliveryId}/cargas", [
            'items' => [['unit' => 'maple', 'amount' => '1', 'eggs_per_unit' => 30]],
        ])->assertOk()->assertJsonPath('data.unit_balances.rows.1.remaining_amount', '2');
        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-002', 'status' => 'delivered',
            'items' => [['unit' => 'maple', 'amount' => '2', 'eggs_per_unit' => 30]],
        ])->assertOk()->assertJsonPath('data.delivered_quantity', 60);
        $this->getJson("/api/v1/repartos/{$deliveryId}")->assertOk()
            ->assertJsonPath('data.unit_balances.rows.1.remaining_amount', '0');
    }

    public function test_fractional_delivery_uses_the_load_conversion_snapshot(): void
    {
        $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own']);
        $unit = ProductionUnit::factory()->create();
        $deliveryId = $this->command('POST', '/repartos', [
            'production_unit_id' => $unit->id,
            'items' => [['unit' => 'maple', 'amount' => '0.5', 'eggs_per_unit' => 30]],
        ])->assertCreated()->json('data.id');

        config()->set('delivery_units.maple.eggs_per_unit', 24);
        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-001', 'status' => 'delivered',
            'items' => [['unit' => 'maple', 'amount' => '0.5', 'eggs_per_unit' => 30]],
        ])->assertOk()->assertJsonPath('data.delivered_quantity', 15);
        $this->getJson("/api/v1/repartos/{$deliveryId}")->assertOk()
            ->assertJsonPath('data.unit_balances.rows.0.remaining_amount', '0');

    }

    public function test_driver_can_record_location_and_delivery_then_close_and_return_stock(): void
    {
        // Preparación: inicia un reparto activo con permisos operativos.
        $this->signIn(['delivery.start', 'delivery.view-own', 'delivery.update-own', 'delivery.location.publish']);
        $unit = ProductionUnit::factory()->create();
        $start = $this->command('POST', '/repartos', ['production_unit_id' => $unit->id, 'quantity' => 120]);
        $start->assertCreated();
        $deliveryId = $start->json('data.id');

        // Request: sincroniza una muestra GPS y repite el mismo lote.
        $location = [[
            'client_event_id' => (string) Str::uuid(),
            'latitude' => -34.73,
            'longitude' => -56.218,
            'accuracy' => 8.5,
            'speed' => 4.2,
            'captured_at' => '2026-09-29T16:00:00-03:00',
        ]];
        $this->postJson("/api/v1/repartos/{$deliveryId}/ubicaciones/lote", ['locations' => $location])
            ->assertOk()
            ->assertJsonPath('data.accepted', 1);
        $this->postJson("/api/v1/repartos/{$deliveryId}/ubicaciones/lote", ['locations' => $location])
            ->assertOk()
            ->assertJsonPath('data.duplicates', 1);

        // Request: registra la entrega de un cliente y cierra con huevos devueltos.
        $this->command('POST', "/repartos/{$deliveryId}/entregas", [
            'client_reference' => 'demo-001',
            'status' => 'delivered',
            'items' => [['unit' => 'huevo', 'amount' => '40', 'eggs_per_unit' => 1]],
        ])->assertOk();
        $closed = $this->command('POST', "/repartos/{$deliveryId}/cierre", [
            'returned_quantity' => 80,
            'notes' => 'Cierre de prueba',
        ])->assertOk();

        // Verificación: el reparto queda cerrado y la devolución vuelve al stock técnico.
        $closed->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.delivered_quantity', 40)
            ->assertJsonPath('data.returned_quantity', 80);
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'completed', 'returned_quantity' => 80]);
        $this->assertDatabaseCount('delivery_location_points', 1);
        $this->assertDatabaseHas('egg_stock_transactions', [
            'reference_type' => 'delivery',
            'reference_id' => $deliveryId,
            'type' => 'distribution_return',
            'quantity' => 80,
        ]);
    }

    public function test_driver_can_query_only_their_active_delivery(): void
    {
        // Preparación: registra repartos activos de dos usuarios en unidades diferentes.
        $firstDriver = $this->signIn(['delivery.start', 'delivery.view-own']);
        $firstUnit = ProductionUnit::factory()->create([
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ]);
        $secondUnit = ProductionUnit::factory()->create([
            'latitude' => '-33.413100',
            'longitude' => '-56.500000',
        ]);
        $firstDelivery = $this->command('POST', '/repartos', [
            'production_unit_id' => $firstUnit->id,
            'quantity' => 10,
        ])->assertCreated();

        $secondDriver = User::factory()->create([
            'pin_hash' => app(PinHasher::class)->hash('0007'),
            'pin_enabled' => true,
            'pin_pepper_version' => config('identity.pin.pepper_version'),
            'pin_daily_window_started_at' => now(),
        ]);
        $secondDriver->givePermissionTo([
            Permission::findOrCreate('delivery.start', 'web'),
            Permission::findOrCreate('delivery.view-own', 'web'),
        ]);
        Sanctum::actingAs($secondDriver, ['api:access']);
        $secondDelivery = $this->command('POST', '/repartos', [
            'production_unit_id' => $secondUnit->id,
            'quantity' => 10,
        ])->assertCreated();

        // Acción: filtra el reparto activo del segundo operador y consulta sus coordenadas de unidad.
        $secondResponse = $this->getJson("/api/v1/repartos/actuales?production_unit_id={$secondUnit->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.production_unit.latitude', '-33.413100')
            ->assertJsonPath('data.0.production_unit.longitude', '-56.500000');
        $this->assertSame($secondDelivery->json('data.id'), $secondResponse->json('data.0.id'));

        // Acción: filtra la unidad propia y confirma que otro repartidor no amplía el resultado.
        Sanctum::actingAs($firstDriver, ['api:access']);
        $firstResponse = $this->getJson("/api/v1/repartos/actuales?production_unit_id={$firstUnit->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame($firstDelivery->json('data.id'), $firstResponse->json('data.0.id'));
        $this->getJson("/api/v1/repartos/actuales?production_unit_id={$secondUnit->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // Flujo: filtra históricos y actuales por unidad y expone sus coordenadas persistidas.
    public function test_monitor_can_filter_current_and_history_by_unit_and_read_unit_coordinates(): void
    {
        // Preparación: crea repartos reales asociados a dos unidades con puntos conocidos.
        $firstUnit = ProductionUnit::factory()->create([
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ]);
        $secondUnit = ProductionUnit::factory()->create([
            'latitude' => '-33.413100',
            'longitude' => '-56.500000',
        ]);
        $firstDelivery = Delivery::factory()->create(['production_unit_id' => $firstUnit->id]);
        Delivery::factory()->create(['production_unit_id' => $secondUnit->id]);
        $this->signIn(['delivery.monitor', 'delivery.history']);

        // Acción: obtiene repartos históricos filtrados y comprueba la ubicación de su unidad.
        $this->getJson("/api/v1/repartos?production_unit_id={$firstUnit->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstDelivery->public_id)
            ->assertJsonPath('data.0.production_unit.latitude', '-34.901100')
            ->assertJsonPath('data.0.production_unit.longitude', '-56.164500');

        // Acción: filtra repartos actuales y consulta el detalle con el mismo contrato de ubicación.
        $current = $this->getJson("/api/v1/repartos/actuales?production_unit_id={$secondUnit->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.production_unit.id', $secondUnit->id)
            ->assertJsonPath('data.0.production_unit.latitude', '-33.413100');
        $this->getJson('/api/v1/repartos/'.$current->json('data.0.id'))
            ->assertOk()
            ->assertJsonPath('data.production_unit.longitude', '-56.500000');

        // Verificación: rechaza identificadores de unidad que no existen.
        $this->getJson('/api/v1/repartos?production_unit_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['production_unit_id']);
    }

    public function test_user_without_delivery_permission_is_forbidden_from_starting_a_delivery(): void
    {
        // Preparación: autentica un usuario que sólo posee un permiso de lectura no relacionado.
        $this->signIn(['identity.personal.login']);

        // Request: intenta iniciar un reparto sin permiso de operación.
        $response = $this->command('POST', '/repartos', ['quantity' => 10]);

        // Verificación: la autorización ocurre antes de cualquier escritura.
        $response->assertForbidden();
        $this->getJson('/api/v1/repartos/clientes')->assertForbidden();
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_driver_cannot_read_another_drivers_delivery_without_monitor_permission(): void
    {
        // Preparación: crea un reparto con un primer repartidor.
        $firstDriver = $this->signIn(['delivery.start', 'delivery.view-own']);
        $unit = ProductionUnit::factory()->create();
        $created = $this->command('POST', '/repartos', ['production_unit_id' => $unit->id, 'quantity' => 10])->assertCreated();
        $deliveryId = $created->json('data.id');

        // Preparación: cambia la sesión a otro repartidor sin permiso de monitoreo.
        $secondDriver = User::factory()->create();
        $secondDriver->givePermissionTo(Permission::findOrCreate('delivery.view-own', 'web'));
        Sanctum::actingAs($secondDriver, ['api:access']);

        // Request: intenta consultar el reparto ajeno.
        $response = $this->getJson("/api/v1/repartos/{$deliveryId}");

        // Verificación: no se revela la información de otro repartidor.
        $response->assertForbidden();
        $this->assertNotSame($firstDriver->id, $secondDriver->id);
        $this->assertInstanceOf(Delivery::class, Delivery::query()->where('public_id', $deliveryId)->first());
    }
}
