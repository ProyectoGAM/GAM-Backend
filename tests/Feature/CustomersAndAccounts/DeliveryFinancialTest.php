<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggPresentation;
use App\Models\Notifications\AppNotification;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Services\IdentityAndAccess\PinHasher;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\ReportingAndAnalytics\ReportExportWriter;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\SimpleExcel\SimpleExcelReader;
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

        // Request: otro repartidor no puede rectificar el cargo.
        $path = '/api/v1/repartos/'.$deliveryId.'/entregas/'.$stopId.'/rectificaciones';
        $data = ['version' => 1, 'motivo' => 'Cantidad ingresada por error', 'lineas' => [['indice' => 0, 'cantidad' => '1']]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $otherDriver = User::factory()->create();
        $otherDriver->syncRoles('delivery');
        Sanctum::actingAs($otherDriver, ['api:access']);
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

    // Flujo: el repartidor reduce cantidades de un reparto activo con saldo positivo y compensa el cargo.
    public function test_driver_can_reduce_a_delivery_with_positive_egg_balance(): void
    {
        // Preparación: registra media presentación con un cobro parcial.
        $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$id, $presentation] = $this->start();
        $event = (string) Str::uuid();
        $stop = $this->postJson('/api/v1/repartos/'.$id.'/entregas', [
            'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2, 'client_event_id' => $event,
            'items' => [['unit' => $presentation->code, 'amount' => '0.5', 'eggs_per_unit' => 24]],
            'payment' => ['amount' => '10', 'payment_method' => 'cash'],
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.id');

        // Mutación: reduce la primera línea y repite la sincronización con la misma clave.
        $path = '/api/v1/repartos/'.$id.'/entregas/'.$stop.'/rectificaciones';
        $payload = ['version' => 1, 'motivo' => 'Recuento en la entrega', 'lineas' => [['indice' => 0, 'cantidad' => '0.25']]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $correction = $this->postJson($path, $payload, $headers)->assertOk()
            ->assertJsonPath('data.antes.saldo_huevos', 12)->assertJsonPath('data.despues.saldo_huevos', 18)
            ->assertJsonPath('data.despues.version', 2)->assertJsonPath('data.despues.cantidad_entregada', 6);
        $this->postJson($path, $payload, $headers)->assertOk()->assertExactJson($correction->json());

        // Consulta: queda el cobro original, el cargo efectivo y una sola salida de huevos.
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '15.250']);
        $this->assertDatabaseCount('customer_account_movements', 3);
        $this->assertDatabaseCount('egg_stock_transactions', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'delivery_stop_corrected', 'operation_id' => $correction->json('data.operation_id')]);
        $this->getJson('/api/v1/repartos/'.$id)->assertOk()
            ->assertJsonPath('data.financial_summary.collected_amount', '10.000')
            ->assertJsonPath('data.financial_summary.merchandise_amount', '25.250');

        // Mutación: al cerrar el reparto, rechaza nuevas rectificaciones del repartidor.
        Delivery::query()->where('public_id', $id)->firstOrFail()->forceFill(['status' => 'completed', 'closed_at' => now()])->save();
        $this->postJson($path, [...$payload, 'version' => 2, 'lineas' => [['indice' => 0, 'cantidad' => '0']]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseHas('delivery_stops', ['id' => $stop, 'version' => 2, 'delivered_quantity' => 6]);
    }

    // Flujo: reemplaza un cobro de entrega, luego lo deja sin efecto y conserva todos los originales.
    public function test_driver_can_replace_a_delivery_payment_and_mark_it_unpaid(): void
    {
        // Preparación: cobra 30 UYU por una entrega de 50,500 UYU.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$id, $presentation] = $this->start();
        $event = (string) Str::uuid();
        $delivery = '/api/v1/repartos/'.$id;
        $stop = $this->postJson($delivery.'/entregas', [
            'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2, 'client_event_id' => $event,
            'items' => [['unit' => $presentation->code, 'amount' => '0.5', 'eggs_per_unit' => 24]],
            'payment' => ['amount' => '30', 'payment_method' => 'transfer', 'payment_reference' => 'comprobante'],
        ], ['Idempotency-Key' => $event])->assertOk();
        $original = $stop->json('data.account_movements.1.id');
        $path = $delivery.'/cobros/'.$original.'/rectificaciones';

        // Mutación: un reemplazo produce una reversión y un cobro ligados a la misma entrega.
        $payload = ['amount' => '20.125', 'reason' => 'Diferencia en el comprobante'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->postJson($path, $payload, $headers)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'reversal')->assertJsonPath('data.0.amount', '30.000')
            ->assertJsonPath('data.0.reverses_movement_id', $original)
            ->assertJsonPath('data.1.type', 'payment')->assertJsonPath('data.1.amount', '-20.125')
            ->assertJsonPath('data.1.delivery_stop_id', $stop->json('data.id'))
            ->assertJsonPath('data.1.payment_method', 'transfer')->assertJsonPath('data.1.payment_reference', 'comprobante');
        $this->postJson($path, $payload, $headers)->assertOk()->assertExactJson($response->json());
        $this->postJson($path, [...$payload, 'amount' => '0'], $headers)->assertConflict();
        $this->postJson($path, $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '30.375']);
        $this->assertDatabaseHas('activity_log', ['event' => 'delivery_payment_corrected', 'operation_id' => $response->json('data.0.operation_id')]);

        // Consulta: el reporte usa el cobro de reemplazo una sola vez.
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);
        $date = now('America/Montevideo')->toDateString();
        $report = '/api/v1/reportes/deliveries.collections/previsualizaciones';
        $this->postJson($report, ['from' => $date, 'to' => $date])->assertOk()
            ->assertJsonPath('data.rows.0.paid_at_delivery', '20.125')
            ->assertJsonPath('data.rows.0.outstanding_at_delivery', '30.375');

        // Mutación: dejar sin efecto el reemplazo carga toda la venta a cuenta corriente.
        Sanctum::actingAs($driver, ['api:access']);
        $this->postJson($delivery.'/cobros/'.$response->json('data.1.id').'/rectificaciones',
            ['amount' => '0', 'reason' => 'El cliente confirmó que no pagó'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'reversal');
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '50.500']);
        $this->assertDatabaseCount('customer_account_movements', 5);
        $this->assertDatabaseCount('egg_stock_transactions', 1);
        $this->getJson($delivery)->assertOk()->assertJsonPath('data.financial_summary.collected_amount', '0.000')
            ->assertJsonPath('data.delivered_quantity', 12)->assertJsonPath('data.stops.0.total_amount', '50.500');

        // Mutación: vuelve a cobrar la misma entrega después de dejar sin efecto el pago anterior.
        $event = (string) Str::uuid();
        $this->postJson($delivery.'/cobros', [
            'client_reference' => $customer->public_id, 'client_event_id' => $event,
            'delivery_stop_id' => $stop->json('data.id'), 'amount' => '50.500', 'payment_method' => 'cash',
        ], ['Idempotency-Key' => $event])->assertOk()->assertJsonPath('data.0.delivery_stop_id', $stop->json('data.id'));

        // Consulta: el reporte cuenta el último cobro y descarta los que se revirtieron en ruta.
        Sanctum::actingAs($admin, ['api:access']);
        $this->postJson($report, ['from' => $date, 'to' => $date])->assertOk()
            ->assertJsonPath('data.rows.0.paid_at_delivery', '50.500')
            ->assertJsonPath('data.rows.0.payment_status', 'Pagado');
    }

    // Flujo: cobra una entrega fiada y distingue lo imputado a la entrega del saldo de cuenta corriente.
    public function test_later_linked_payments_update_the_delivery_report(): void
    {
        // Preparación: registra una venta de 50,500 UYU inicialmente fiada.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$id, $presentation] = $this->start();
        $event = (string) Str::uuid();
        $delivery = '/api/v1/repartos/'.$id;
        $stop = $this->postJson($delivery.'/entregas', [
            'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2, 'client_event_id' => $event,
            'items' => [['unit' => $presentation->code, 'amount' => '0.5', 'eggs_per_unit' => 24]],
            'payment' => ['amount' => '0'],
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.id');

        // Mutación: registra un pago independiente y un cobro parcial imputado a la entrega.
        $path = $delivery.'/cobros';
        $event = (string) Str::uuid();
        $this->postJson($path, [
            'client_reference' => $customer->public_id, 'client_event_id' => $event, 'amount' => '2', 'payment_method' => 'cash',
        ], ['Idempotency-Key' => $event])->assertOk()->assertJsonPath('data.0.delivery_stop_id', null);
        $event = (string) Str::uuid();
        $payload = ['client_reference' => $customer->public_id, 'delivery_stop_id' => $stop,
            'client_event_id' => $event, 'amount' => '20.250', 'payment_method' => 'cash'];
        $headers = ['Idempotency-Key' => $event];
        $payment = $this->postJson($path, $payload, $headers)->assertOk()
            ->assertJsonPath('data.0.delivery_stop_id', $stop)->assertJsonPath('data.0.balance_after', '28.250');
        $this->postJson($path, $payload, $headers)->assertOk()->assertExactJson($payment->json());
        $this->postJson($path, array_diff_key($payload, ['delivery_stop_id' => true]), $headers)->assertConflict();
        $this->assertDatabaseHas('activity_log', ['event' => 'customer_account_payment', 'operation_id' => $payment->json('data.0.operation_id')]);

        // Consulta: el reporte refleja pago parcial y separa la deuda actual de lo pendiente por la entrega.
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);
        $date = now('America/Montevideo')->toDateString();
        $period = ['from' => $date, 'to' => $date];
        $report = '/api/v1/reportes/deliveries.collections/previsualizaciones';
        $this->postJson($report, $period)->assertOk()
            ->assertJsonPath('data.rows.0.paid_at_delivery', '20.250')
            ->assertJsonPath('data.rows.0.outstanding_at_delivery', '30.250')
            ->assertJsonPath('data.rows.0.current_debt', '28.250')
            ->assertJsonPath('data.rows.0.payment_status', 'Pago parcial');

        // Mutación: completa el cobro mediante otro medio en la misma entrega.
        Sanctum::actingAs($driver, ['api:access']);
        $event = (string) Str::uuid();
        $this->postJson($path, [...$payload, 'client_event_id' => $event, 'amount' => '30.250', 'payment_method' => 'transfer'],
            ['Idempotency-Key' => $event])->assertOk()->assertJsonPath('data.0.delivery_stop_id', $stop);
        $this->assertDatabaseCount('customer_account_movements', 4);
        $this->assertDatabaseCount('egg_stock_transactions', 1);

        // Consulta: ambos cobros imputados completan la venta y aparecen como medio mixto.
        Sanctum::actingAs($admin, ['api:access']);
        $this->postJson($report, [...$period, 'filters' => [['field' => 'payment_method', 'operator' => 'eq', 'value' => 'Mixto']]])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.paid_at_delivery', '50.500')
            ->assertJsonPath('data.rows.0.outstanding_at_delivery', '0.000')->assertJsonPath('data.rows.0.payment_status', 'Pagado');
    }

    // Flujo: un cobro vinculado exige una entrega del cliente, realizada y contabilizada en ese reparto.
    public function test_linked_payment_rejects_a_foreign_or_unaccounted_stop(): void
    {
        // Preparación: crea una entrega válida y otro reparto del mismo repartidor.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        $otherCustomer = Customer::factory()->withAccount()->create();
        [$id, $presentation] = $this->start();
        $other = Delivery::factory()->create(['driver_id' => $driver->id]);
        $event = (string) Str::uuid();
        $stop = $this->postJson('/api/v1/repartos/'.$id.'/entregas', [
            'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2, 'client_event_id' => $event,
            'items' => [['unit' => $presentation->code, 'amount' => '0.5', 'eggs_per_unit' => 24]],
            'payment' => ['amount' => '0'],
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.id');
        $event = (string) Str::uuid();
        $payload = ['client_reference' => $customer->public_id, 'delivery_stop_id' => $stop,
            'client_event_id' => $event, 'amount' => '20', 'payment_method' => 'cash'];
        $headers = ['Idempotency-Key' => $event];

        // Requests: rechaza la referencia de cliente o reparto ajeno y el identificador nulo.
        $path = '/api/v1/repartos/'.$id.'/cobros';
        $this->postJson($path, [...$payload, 'client_reference' => $otherCustomer->public_id], $headers)->assertNotFound();
        $this->postJson('/api/v1/repartos/'.$other->public_id.'/cobros', $payload, $headers)->assertNotFound();
        $this->postJson($path, [...$payload, 'delivery_stop_id' => null], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('delivery_stop_id');

        // Mutación: una entrega sin contabilidad conciliada no recibe cobros imputados.
        DeliveryStop::query()->findOrFail($stop)->forceFill(['accounting_status' => 'legacy_unreconciled'])->save();
        $this->postJson($path, $payload, $headers)->assertConflict();
        $this->assertDatabaseCount('customer_account_movements', 1);
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '50.500']);
    }

    // Flujo: limita el historial a las cinco últimas entregas del cliente sin restringir por repartidor.
    public function test_driver_sees_latest_five_customer_deliveries_across_drivers(): void
    {
        // Preparación: seis entregas propias, una visita sin entrega más reciente y una entrega de otro repartidor.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        $attributes = ['customer_id' => $customer->id, 'client_reference' => $customer->public_id,
            'client_name' => $customer->displayName(), 'address' => 'Dirección', 'latitude' => '-34.90', 'longitude' => '-56.20',
            'sequence' => 1, 'status' => DeliveryStopStatus::Delivered, 'delivered_quantity' => 24];
        foreach (range(1, 6) as $day) {
            $delivery = Delivery::factory()->completed()->create(['driver_id' => $driver->id]);
            $delivery->stops()->create([...$attributes, 'visited_at' => now()->subDays($day), 'delivered_quantity' => $day]);
        }
        $delivery = Delivery::factory()->create(['driver_id' => $driver->id]);
        $delivery->stops()->create([...$attributes, 'status' => DeliveryStopStatus::NotDelivered, 'visited_at' => now(), 'delivered_quantity' => 0]);
        $otherDelivery = Delivery::factory()->completed()->create();
        $otherDelivery->stops()->create([...$attributes, 'visited_at' => now()]);
        $path = '/api/v1/repartos/clientes/'.$customer->public_id.'/historial';

        // Consulta: excluye la visita sin entrega e incluye el reparto del otro conductor.
        $history = $this->getJson($path)->assertOk()->assertJsonCount(5, 'data.deliveries')
            ->assertJsonCount(0, 'data.payments');
        $this->assertSame([24, 1, 2, 3, 4], array_column($history->json('data.deliveries'), 'delivered_quantity'));

        // Consulta: cuando se aplica un alcance de UP, las demás entregas quedan fuera del historial.
        app(ProductionUnitAccess::class)->within($otherDelivery->production_unit_id, fn () => $this->getJson($path))
            ->assertOk()->assertJsonCount(1, 'data.deliveries')->assertJsonPath('data.deliveries.0.delivered_quantity', 24);

        // Consulta: una cuenta sin permiso operativo recibe acceso denegado.
        Sanctum::actingAs(User::factory()->create(), ['api:access']);
        $this->getJson($path)->assertForbidden();
    }

    // Flujo: consulta cobros del cliente realizados en otros repartos y fuera de reparto.
    public function test_customer_history_includes_payments_across_deliveries_and_standalone_payments(): void
    {
        // Preparación: registra un cobro fuera de reparto como administrador.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);
        $standalone = $this->postJson('/api/v1/clientes/'.$customer->public_id.'/pagos',
            ['amount' => '10.125', 'payment_method' => 'transfer'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertSuccessful()->json('data.0.id');

        // Mutación: otro repartidor registra un cobro en su propio reparto.
        $otherDriver = User::factory()->create();
        $otherDriver->syncRoles('delivery');
        Sanctum::actingAs($otherDriver, ['api:access']);
        $delivery = Delivery::factory()->create(['driver_id' => $otherDriver->id]);
        $event = (string) Str::uuid();
        $collection = $this->postJson('/api/v1/repartos/'.$delivery->public_id.'/cobros', [
            'client_reference' => $customer->public_id, 'client_event_id' => $event, 'amount' => '15.000', 'payment_method' => 'cash',
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.0.id');

        // Consulta: el historial personal incluye ambos cobros y el alcance de UP conserva el de su reparto.
        Sanctum::actingAs($driver, ['api:access']);
        $path = '/api/v1/repartos/clientes/'.$customer->public_id.'/historial';
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.id', $collection)
            ->assertJsonPath('data.payments.1.id', $standalone);
        app(ProductionUnitAccess::class)->within($delivery->production_unit_id, fn () => $this->getJson($path))
            ->assertOk()->assertJsonCount(1, 'data.payments')->assertJsonPath('data.payments.0.id', $collection);
    }

    // Flujo: el mapa deja de mostrar como cobrado al cliente sin visitas ni pagos vigentes.
    public function test_voiding_a_standalone_payment_updates_the_customer_map(): void
    {
        // Preparación: registra un cobro independiente a un cliente dentro del mapa.
        $this->driver();
        $customer = Customer::factory()->withAccount()->create(['latitude' => '-34.90', 'longitude' => '-56.20']);
        [$id] = $this->start();
        $event = (string) Str::uuid();
        $path = '/api/v1/repartos/'.$id.'/cobros';
        $payment = $this->postJson($path, [
            'client_reference' => $customer->public_id, 'client_event_id' => $event, 'amount' => '15', 'payment_method' => 'cash',
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.0.id');
        $markers = '/api/v1/clientes/mapa?west=-57&east=-56&south=-35&north=-34&delivery_id='.$id;
        $history = '/api/v1/repartos/clientes/'.$customer->public_id.'/historial';
        $this->getJson($markers.'&visit_status=paid')->assertOk()->assertJsonPath('data.0.id', $customer->public_id);
        $this->getJson($history)->assertOk()->assertJsonCount(0, 'data.deliveries')->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.id', $payment)
            ->assertJsonPath('data.payments.0.amount', '15.000')
            ->assertJsonPath('data.payments.0.payment_method', 'cash')
            ->assertJsonMissingPath('data.payments.0.balance_after');

        // Mutación y consulta: deshace el saldo a favor y el cliente vuelve a pendiente.
        $this->postJson($path.'/'.$payment.'/rectificaciones',
            ['amount' => '0', 'reason' => 'No se recibió dinero'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.delivery_stop_id', null);
        $this->getJson($history)->assertOk()->assertJsonCount(0, 'data.payments');
        $this->getJson($markers.'&visit_status=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($markers.'&visit_status=pending')->assertOk()
            ->assertJsonPath('data.0.id', $customer->public_id)->assertJsonPath('data.0.visit_status', 'pending');
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '0.000']);
    }

    // Flujo: limita la rectificación de cobros al reparto propio activo y a un pago de ese reparto.
    public function test_payment_correction_rejects_foreign_payments_and_closed_deliveries(): void
    {
        // Preparación: registra un cobro independiente y crea otro reparto del mismo conductor.
        $driver = $this->driver();
        $customer = Customer::factory()->withAccount()->create();
        [$id] = $this->start();
        $event = (string) Str::uuid();
        $payment = $this->postJson('/api/v1/repartos/'.$id.'/cobros', [
            'client_reference' => $customer->public_id, 'client_event_id' => $event, 'amount' => '15', 'payment_method' => 'cash',
        ], ['Idempotency-Key' => $event])->assertOk()->json('data.0.id');
        $other = Delivery::factory()->create(['driver_id' => $driver->id]);
        $suffix = '/cobros/'.$payment.'/rectificaciones';
        $payload = ['amount' => '0', 'reason' => 'Cobro ingresado por error'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        // Requests: el cobro ajeno al reparto no se encuentra y un tercero no puede editar.
        $this->postJson('/api/v1/repartos/'.$other->public_id.$suffix, $payload, $headers)->assertNotFound();
        $anotherDriver = User::factory()->create();
        $anotherDriver->syncRoles('delivery');
        Sanctum::actingAs($anotherDriver, ['api:access']);
        $path = '/api/v1/repartos/'.$id.$suffix;
        $this->postJson($path, $payload, $headers)->assertForbidden();

        // Requests: rechaza una cantidad inválida y luego una rectificación nueva posterior al cierre.
        Sanctum::actingAs($driver, ['api:access']);
        $this->postJson($path, ['amount' => '-1'], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'reason']);
        Delivery::query()->where('public_id', $id)->firstOrFail()->forceFill(['status' => 'completed', 'closed_at' => now()])->save();
        $this->postJson($path, $payload, $headers)->assertConflict();
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'balance' => '-15.000']);
        $this->assertDatabaseCount('customer_account_movements', 1);
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

    // Flujo: el reporte distingue cobro histórico y saldo actual, e incluye los totales y precios del reparto.
    public function test_complete_delivery_report_projects_prices_collections_and_current_debt(): void
    {
        // Preparación: abre un reparto y registra dos entregas, una parcial con cheque y otra fiada.
        $this->driver();
        $first = Customer::factory()->withAccount()->create();
        $second = Customer::factory()->withAccount()->create();
        [$id, $presentation, $unit] = $this->start();
        foreach ([[$first, ['unit_price' => 200], ['amount' => '5', 'payment_method' => 'check']],
            [$second, [], ['amount' => '0']]] as [$customer, $price, $payment]) {
            $key = (string) Str::uuid();
            $this->postJson('/api/v1/repartos/'.$id.'/entregas', [
                'client_reference' => $customer->public_id, 'status' => 'delivered', 'accounting_version' => 2,
                'client_event_id' => $key, 'notes' => 'Entregar en recepción',
                'items' => [['unit' => $presentation->code, 'amount' => '0.125', 'eggs_per_unit' => 24, ...$price]],
                'payment' => $payment,
            ], ['Idempotency-Key' => $key])->assertOk();
        }

        // Mutación: cobra la deuda después, devuelve el sobrante y cambia el precio del catálogo.
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/repartos/'.$id.'/cobros', [
            'client_reference' => $second->public_id, 'client_event_id' => $key, 'amount' => '12.625', 'payment_method' => 'transfer',
        ], ['Idempotency-Key' => $key])->assertOk();
        $this->postJson('/api/v1/repartos/'.$id.'/cierre', [
            'pin' => '0007', 'return_production_unit_id' => $unit->id,
            'returned_items' => [['unit' => $presentation->code, 'amount' => '0.75', 'eggs_per_unit' => 24]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $presentation->update(['default_unit_price' => 999]);

        // Consulta: usa la fecha local y comprueba los tres importes de la entrega pagada en parte.
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        Sanctum::actingAs($admin, ['api:access']);
        $date = now('America/Montevideo')->toDateString();
        $path = '/api/v1/reportes/deliveries.collections/previsualizaciones';
        $period = ['from' => $date, 'to' => $date];
        $this->postJson($path, [...$period, 'filters' => [
            ['field' => 'payment_method', 'operator' => 'eq', 'value' => 'Cheque'],
            ['field' => 'current_debt', 'operator' => 'gte', 'value' => '20.000'],
            ['field' => 'notes', 'operator' => 'contains', 'value' => 'RECEPCIÓN'],
        ]])->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.quantity', '3')->assertJsonPath('data.rows.0.merchandise_value', '25.000')
            ->assertJsonPath('data.rows.0.default_merchandise_value', '12.625')
            ->assertJsonPath('data.rows.0.entered_unit_prices', $presentation->name.': $200')
            ->assertJsonPath('data.rows.0.paid_at_delivery', '5.000')
            ->assertJsonPath('data.rows.0.outstanding_at_delivery', '20.000')
            ->assertJsonPath('data.rows.0.payment_status', 'Pago parcial')
            ->assertJsonPath('data.rows.0.current_debt_status', 'Con deuda');

        // Consulta: la entrega fiada conserva el cero histórico con la cuenta actual al día.
        $this->postJson($path, [...$period, 'filters' => [['field' => 'client_id', 'operator' => 'eq', 'value' => $second->public_id]]])
            ->assertOk()->assertJsonPath('data.rows.0.paid_at_delivery', '0.000')
            ->assertJsonPath('data.rows.0.outstanding_at_delivery', '12.625')
            ->assertJsonPath('data.rows.0.current_debt_status', 'Al día');

        // Consulta: al agrupar, la carga y devolución se cuentan una vez por reparto.
        $this->postJson($path, [...$period, 'groupings' => ['day'], 'metrics' => [
            'loaded_total', 'returned_total', 'egg_count', 'collected_total', 'current_debt_total', 'customers_settled',
        ]])->assertOk()->assertJsonPath('data.rows.0', [
            'day' => $date, 'loaded_total' => '24', 'returned_total' => '18', 'egg_count' => '6',
            'collected_total' => '5.000', 'current_debt_total' => '20.000', 'customers_settled' => '1',
        ]);

        // Exportación: verifica una planilla real y bloquea el enlace público de la fuente financiera.
        Storage::fake('local');
        $source = app(ReportSourceRegistry::class)->get('deliveries.collections');
        $query = app(ReportQueryNormalizer::class)->normalize('deliveries.collections', [...$period, 'columns' => ['notes', 'paid_at_delivery', 'current_debt']]);
        $export = ReportExport::factory()->for($admin)->create(['source_key' => $query->sourceKey, 'definition_version' => $query->definitionVersion, 'query' => $query->toArray()]);
        $file = app(ReportExportWriter::class)->write($export, $source, $query);
        $export->update([...$file, 'status' => 'completed', 'completed_at' => now()]);
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file['path']))->getRows()->all();
        $this->assertCount(2, $rows);
        $this->assertSame(['Observaciones', 'Cobrado en la entrega', 'Deuda actual'], array_keys($rows[0]));
        $this->postJson('/api/v1/exportaciones-reportes/'.$export->id.'/enlaces-temporales')->assertForbidden();
        $this->get(URL::temporarySignedRoute('api.v1.report-exports.download.es', now()->addMinutes(10), [
            'reportExport' => $export->id, 'share' => 1, 'view' => 'html',
        ]))->assertForbidden();
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
