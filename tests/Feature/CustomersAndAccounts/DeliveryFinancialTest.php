<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggPresentation;
use App\Models\Notifications\AppNotification;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DeliveryFinancialTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: una rectificación reduce el cargo y conserva el pago, el reintento y la historia.
    public function test_active_shortfall_rectification_compensates_the_sale_and_preserves_payments(): void
    {
        // Preparación: registra 36 huevos sobre una carga de 24 y un pago superior al importe corregido.
        $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$deliveryId, $presentation] = $this->start();
        $event = (string) Str::uuid();
        $stopId = $this->postJson('/api/v1/repartos/'.$deliveryId.'/entregas', [
            'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2, 'client_event_id' => $event,
            'items' => [['unit' => $presentation->code, 'amount' => '1.5', 'eggs_per_unit' => 24]],
            'payment' => ['amount' => '120.125', 'payment_method' => 'cash'],
        ], ['Idempotency-Key' => $event])->assertOk()->assertJsonPath('data.version', 1)->json('data.id');

        // Request: el repartidor carece del permiso administrativo para compensar el cargo.
        $path = '/api/v1/repartos/'.$deliveryId.'/entregas/'.$stopId.'/rectificaciones';
        $data = ['version' => 1, 'motivo' => 'Cantidad ingresada por error', 'lineas' => [['indice' => 0, 'cantidad' => '1']]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson($path, $data, $headers)->assertForbidden();
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);
        $presentation->update(['default_unit_price' => 200]);

        // Request: conserva el precio de la entrega, compensa la diferencia y devuelve el mismo historial en reintentos.
        $correction = $this->postJson($path, $data, $headers)->assertOk()->assertJsonPath('data.antes.importe', '151.500')
            ->assertJsonPath('data.despues.importe', '101.000')->assertJsonPath('data.despues.version', 2)
            ->assertJsonPath('data.despues.saldo_huevos', 0);
        $this->postJson($path, $data, $headers)->assertOk()->assertJsonPath('data.operation_id', $correction->json('data.operation_id'));
        $this->postJson($path, $data, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/v1/repartos/'.$deliveryId.'/entregas/'.($stopId + 1).'/rectificaciones', $data,
            ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

        // Consulta: el importe vigente y el crédito coinciden y la compensación exige su operación de origen.
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '-19.125']);
        $this->assertDatabaseHas('customer_account_movements', ['delivery_stop_id' => $stopId, 'type' => 'adjustment_decrease', 'amount' => '-50.500']);
        $this->assertDatabaseCount('customer_account_movements', 3);
        $this->assertDatabaseCount('egg_stock_transactions', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'delivery_stop_corrected', 'operation_id' => $correction->json('data.operation_id')]);
        $this->getJson('/api/v1/repartos/'.$deliveryId)->assertOk()->assertJsonPath('data.remaining_quantity', 0)
            ->assertJsonPath('data.financial_summary.collected_amount', '120.125')
            ->assertJsonPath('data.financial_summary.customers.0.merchandise_amount', '101.000')
            ->assertJsonPath('data.rectificaciones_entregas.0.operation_id', $correction->json('data.operation_id'));
        $this->postJson('/api/v1/clientes/'.$customer->public_id.'/movimientos/'.$correction->json('data.movimiento_cuenta_id').'/reversion',
            ['reason' => 'Intento de reversión separada'], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    // Flujo: un cierre histórico conserva su resumen y acredita el sobrante rectificado en la UP confirmada.
    public function test_closed_shortfall_can_be_rectified_with_an_explicit_return_unit(): void
    {
        // Preparación: registra y contabiliza una entrega antes de simular el cierre negativo legado.
        $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$deliveryId, $presentation, $unit] = $this->start();
        $event = (string) Str::uuid();
        $stopId = $this->postJson('/api/v1/repartos/'.$deliveryId.'/entregas', [
            'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2, 'client_event_id' => $event,
            'items' => [['unit' => $presentation->code, 'amount' => '1.5', 'eggs_per_unit' => 24]], 'payment' => ['amount' => '0'],
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.id');
        Delivery::query()->where('public_id', $deliveryId)->firstOrFail()->forceFill(['status' => 'completed', 'closed_at' => now(),
            'financial_snapshot' => ['currency' => 'UYU', 'merchandise_amount' => '151.500']])->save();
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);

        // Request: omitir el destino revierte toda la operación, incluido el ajuste de cuenta.
        $this->getJson('/api/v1/alertas-operativas')->assertOk()->assertJsonFragment(['tipo' => 'delivery_egg_balance_negative']);
        $path = '/api/v1/repartos/'.$deliveryId.'/entregas/'.$stopId.'/rectificaciones';
        $data = ['version' => 1, 'motivo' => 'Confirmación de remanente físico', 'lineas' => [['indice' => 0, 'cantidad' => '0.75']]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson($path, $data, $headers)->assertUnprocessable()->assertJsonValidationErrors('return_production_unit_id');
        $this->assertDatabaseCount('customer_account_movements', 1);
        $this->assertDatabaseCount('egg_stock_transactions', 1);

        // Request y consulta: acredita seis huevos y conserva el cierre histórico junto al resultado corregido.
        $this->postJson($path, [...$data, 'return_production_unit_id' => $unit->id], $headers)->assertOk()
            ->assertJsonPath('data.despues.huevos_devueltos', 6)->assertJsonPath('data.despues.saldo_huevos', 0);
        $this->assertDatabaseHas('egg_stock_transactions', ['reference_id' => $deliveryId, 'production_unit_id' => $unit->id,
            'type' => 'distribution_return', 'quantity' => 6]);
        $this->getJson('/api/v1/repartos/'.$deliveryId)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.closed_financial_summary.merchandise_amount', '151.500')
            ->assertJsonPath('data.financial_summary.merchandise_amount', '75.750')->assertJsonPath('data.remaining_quantity', 0);
        $this->getJson('/api/v1/alertas-operativas')->assertOk()->assertJsonMissing(['tipo' => 'delivery_egg_balance_negative']);
    }

    // Flujo: recupera una alerta de un reparto legado y corrige su cantidad sin crear una deuda inexistente.
    public function test_legacy_closed_shortfall_without_lines_keeps_unknown_price_and_accounting_status(): void
    {
        // Preparación: crea un reparto con aviso pendiente y una entrega histórica sin líneas ni cargo.
        $driver = $this->driver();
        $delivery = Delivery::factory()->completed()->create(['driver_id' => $driver->id, 'loaded_quantity' => 10]);
        $stop = DeliveryStop::query()->create(['delivery_id' => $delivery->id, 'client_reference' => 'cliente-historico',
            'client_name' => 'Cliente histórico', 'address' => 'Dirección', 'latitude' => '-34.90', 'longitude' => '-56.20',
            'sequence' => 1, 'status' => 'delivered', 'delivered_quantity' => 14, 'visited_at' => now()]);
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);
        $notice = AppNotification::factory()->create(['recipient_id' => $admin->id, 'type' => 'delivery_egg_balance_negative',
            'module' => 'deliveries', 'entity_type' => 'delivery', 'entity_id' => $delivery->public_id, 'source_key' => $delivery->public_id,
            'production_unit_id' => $delivery->production_unit_id, 'is_alert' => true, 'urgency' => 'alto']);

        // Mutación y consulta: recupera sólo un episodio y conserva la referencia al reparto cerrado.
        $migration = require database_path('migrations/2026_11_07_235210_backfill_delivery_shortfall_alerts.php');
        $migration->up();
        $migration->up();
        $this->getJson('/api/v1/alertas-operativas')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.entidad.id', $delivery->public_id);

        // Request: corrige la cantidad registrada y resuelve el aviso, conservando el precio como desconocido.
        $this->postJson('/api/v1/repartos/'.$delivery->public_id.'/entregas/'.$stop->id.'/rectificaciones',
            ['version' => 1, 'motivo' => 'Recuento histórico', 'cantidad_entregada' => 10],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.despues.saldo_huevos', 0)
            ->assertJsonPath('data.despues.importe', null)->assertJsonPath('data.movimiento_cuenta_id', null);
        $this->assertDatabaseCount('customer_account_movements', 0);
        $this->assertNotNull($notice->fresh()->resolved_at);
    }

    private function driver(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        config(['identity.pin.pepper' => 'test-only-customer-pin-pepper']);
        $driver = User::factory()->create(['operation_pin_hash' => app(PinHasher::class)->hash('0007')]);
        $driver->syncRoles(['delivery']);
        Sanctum::actingAs($driver, ['api:access']);

        return $driver;
    }

    private function start(): array
    {
        $presentation = EggPresentation::factory()->create(['eggs_per_unit' => 24, 'default_unit_price' => 101]);
        $unit = ProductionUnit::factory()->create();
        $id = $this->postJson('/api/v1/repartos', ['pin' => '0007', 'vehicle_id' => Vehicle::factory()->create()->public_id, 'production_unit_id' => $unit->id,
            'items' => [['unit' => $presentation->code, 'amount' => '1', 'eggs_per_unit' => 24]]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        return [$id, $presentation, $unit];
    }

    /** La entrega genera cargo y pago precisos en una sola operación sin volver a descontar stock. */
    public function test_sale_and_partial_payment_are_atomic_and_replay_without_duplicates(): void
    {
        // Carga real y cliente persistente.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create(['created_by' => $driver->id]);
        [$id, $presentation] = $this->start();
        $key = (string) Str::uuid();
        $data = ['client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2,
            'client_event_id' => $key, 'items' => [['unit' => $presentation->code, 'amount' => '0.125', 'eggs_per_unit' => 24]],
            'payment' => ['amount' => '2.125', 'payment_method' => 'cash']];
        // Confirmación y reintento de una respuesta perdida.
        $this->postJson('/api/v1/repartos/'.$id.'/entregas', $data, ['Idempotency-Key' => $key])->assertOk()
            ->assertJsonPath('data.total_amount', '12.625')->assertJsonPath('data.accounting_status', 'posted')->assertJsonCount(2, 'data.account_movements');
        $this->postJson('/api/v1/repartos/'.$id.'/entregas', $data, ['Idempotency-Key' => $key])->assertOk();
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '10.500', 'version' => 2]);
        $this->assertDatabaseCount('customer_account_movements', 2);
        $this->assertDatabaseCount('egg_stock_transactions', 1);
        $this->getJson('/api/v1/repartos/'.$id)->assertOk()->assertJsonPath('data.financial_summary.collected_amount', '2.125')
            ->assertJsonPath('data.financial_summary.merchandise_amount', '12.625');
    }

    /** Los cobros independientes no consumen huevos ni chocan con la restricción de una entrega. */
    public function test_multiple_collection_only_payments_work_for_inactive_customer_without_location(): void
    {
        // Cuenta inactiva e incompleta a la que todavía se le puede cobrar.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create(['created_by' => $driver->id, 'status' => 'inactive', 'latitude' => null, 'longitude' => null, 'address' => null]);
        [$id] = $this->start();
        foreach (['15.000', '5.125'] as $amount) {
            $key = (string) Str::uuid();
            $data = ['client_reference' => $customer->public_id, 'client_event_id' => $key, 'amount' => $amount, 'payment_method' => 'transfer'];
            $this->postJson('/api/v1/repartos/'.$id.'/cobros', $data, ['Idempotency-Key' => $key])->assertOk();
            $this->postJson('/api/v1/repartos/'.$id.'/cobros', $data, ['Idempotency-Key' => $key])->assertOk();
        }
        $this->assertDatabaseCount('delivery_stops', 0);
        $this->assertDatabaseCount('egg_stock_transactions', 1);
        $this->assertDatabaseCount('customer_account_movements', 2);
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '-20.125']);
        $this->getJson('/api/v1/repartos/'.$id)->assertOk()->assertJsonPath('data.financial_summary.by_method.transfer', '20.125');
    }

    /** Una cola vieja no confirmada exige revisión del cobro en lugar de inventar una venta fiada. */
    public function test_legacy_unconfirmed_command_does_not_create_debt(): void
    {
        // Solicitud anterior al contrato financiero.
        $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$id, $presentation] = $this->start();
        $this->postJson('/api/v1/repartos/'.$id.'/entregas', ['client_reference' => $customer->public_id, 'status' => 'delivered',
            'items' => [['unit' => $presentation->code, 'amount' => '1', 'eggs_per_unit' => 24]]], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('delivery_stops', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    /** Un reintento histórico confirmado conserva su entrega y nunca la contabiliza otra vez. */
    public function test_confirmed_legacy_delivery_replays_without_generating_account_movements(): void
    {
        // Reconstrucción explícita de un registro histórico sin cliente real vinculado.
        $driver = $this->driver();
        $delivery = Delivery::factory()->completed()->create(['driver_id' => $driver->id]);
        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'client_reference' => 'demo-001', 'status' => 'delivered',
            'items' => [['unit' => 'huevo', 'amount' => '1', 'eggs_per_unit' => 1]]];
        DeliveryStop::query()->create(['delivery_id' => $delivery->id, 'client_reference' => 'demo-001', 'client_name' => 'Cliente anterior',
            'address' => 'Dirección anterior', 'latitude' => '-34.73', 'longitude' => '-56.21', 'sequence' => 1,
            'status' => DeliveryStopStatus::Delivered, 'delivered_quantity' => 1, 'total_amount' => '10.000',
            'idempotency_key' => $key, 'request_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'visited_at' => now()]);
        unset($payload['idempotency_key']);
        // Reintento idéntico, incluso con el reparto ya cerrado.
        $this->postJson('/api/v1/repartos/'.$delivery->public_id.'/entregas', $payload, ['Idempotency-Key' => $key])->assertOk()
            ->assertJsonPath('data.accounting_status', 'legacy_unreconciled');
        $this->assertDatabaseCount('customer_account_movements', 0);
        $this->getJson('/api/v1/repartos/'.$delivery->public_id)->assertOk()->assertJsonPath('data.financial_summary.collected_amount', null);
    }

    /** El resumen de cierre es histórico y una corrección posterior se muestra sin reescribirlo. */
    public function test_closed_summary_preserves_original_and_exposes_compensating_payment(): void
    {
        // Sólo cobro y devolución de toda la carga.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$id, $presentation, $unit] = $this->start();
        $key = (string) Str::uuid();
        $movement = $this->postJson('/api/v1/repartos/'.$id.'/cobros', ['client_reference' => $customer->public_id,
            'client_event_id' => $key, 'amount' => '20.125', 'payment_method' => 'cash'], ['Idempotency-Key' => $key])->assertOk()->json('data.0.id');
        $this->postJson('/api/v1/repartos/'.$id.'/cierre', ['pin' => '0007', 'return_production_unit_id' => $unit->id,
            'returned_items' => [['unit' => $presentation->code, 'amount' => '1', 'eggs_per_unit' => 24]]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.closed_financial_summary.collected_amount', '20.125');
        // Un administrador corrige el cobro después del cierre.
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        Sanctum::actingAs($admin, ['api:access']);
        $this->postJson('/api/v1/clientes/'.$customer->public_id.'/movimientos/'.$movement.'/reversion', ['reason' => 'Cobro duplicado'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/v1/repartos/'.$id)->assertOk()->assertJsonPath('data.financial_summary.collected_amount', '0.000')
            ->assertJsonPath('data.closed_financial_summary.collected_amount', '20.125')->assertJsonCount(2, 'data.account_movements');
    }

    /** El mapa tiene límite real y no expone fichas fiscales ni datos financieros. */
    public function test_map_limits_markers_and_rejects_foreign_delivery_context(): void
    {
        // Marcadores del área y contexto perteneciente a otro repartidor.
        $driver = $this->driver();
        Customer::factory()->count(4)->withAccount()->create();
        $this->getJson('/api/v1/clientes/mapa?west=-57&east=-56&south=-35&north=-34&limit=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true)
            ->assertJsonMissingPath('data.0.document_number')->assertJsonMissingPath('data.0.account');
        $other = Delivery::factory()->create();
        $this->getJson('/api/v1/clientes/mapa?west=-57&east=-56&south=-35&north=-34&delivery_id='.$other->public_id)->assertForbidden();
    }
}
