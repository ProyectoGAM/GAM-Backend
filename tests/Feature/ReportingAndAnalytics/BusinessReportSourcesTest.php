<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Actions\Inventory\RecordManualEggStockAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryLoad;
use App\Models\Deliveries\DeliveryStop;
use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingMeasurement;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleFuelEntry;
use App\Models\Vehicles\VehicleMaintenance;
use App\Models\Vehicles\VehicleUsage;
use App\Services\Lots\RunLotsCommand;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class BusinessReportSourcesTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: el valor entregado conserva los importes conocidos e identifica los históricos sin precio.
    public function test_delivery_metrics_distinguish_missing_prices_zero_and_empty_periods(): void
    {
        // Preparación: crea entregas con importe, sin precio y con cero explícito.
        $actor = $this->actor(['can_view_reports', 'can_view_delivery_history']);
        Sanctum::actingAs($actor);
        $delivery = Delivery::factory()->completed()->create();
        foreach ([['2026-11-01', '10.125'], ['2026-11-02', null], ['2026-11-03', '0.000']] as $index => [$date, $amount]) {
            DeliveryStop::query()->create([
                'delivery_id' => $delivery->id, 'client_reference' => 'cliente-'.$index, 'client_name' => 'Cliente', 'address' => 'Dirección',
                'latitude' => '-34.9000000', 'longitude' => '-56.2000000', 'sequence' => $index + 1, 'status' => 'delivered',
                'visited_at' => Carbon::parse($date.'T12:00:00-03:00'), 'total_amount' => $amount, 'delivered_quantity' => 1,
            ]);
        }

        // Consulta: los conteos distinguen cobertura parcial, ausencia de precio y ausencia de hechos.
        $path = '/api/v1/reportes/deliveries.deliveries/previsualizaciones';
        $metrics = ['merchandise_total', 'delivery_count', 'unpriced_delivery_count'];
        foreach ([['2026-11-01', '2026-11-03', '10.125', '3', '1'], ['2026-11-02', '2026-11-02', null, '1', '1'],
            ['2026-11-03', '2026-11-03', '0.000', '1', '0'], ['2026-11-04', '2026-11-04', null, '0', '0']] as [$from, $to, $value, $count, $missing]) {
            $this->postJson($path, ['from' => $from, 'to' => $to, 'metrics' => $metrics])->assertOk()
                ->assertJsonPath('data.rows.0', ['merchandise_total' => $value, 'delivery_count' => $count, 'unpriced_delivery_count' => $missing]);
        }
    }

    // Flujo: los gastos agregan sólo importes efectivos y separan moneda, UP y permisos de origen.
    public function test_expense_summary_groups_recorded_costs_by_currency_and_scope(): void
    {
        // Preparación: crea mantenimiento activo, anulado y costos generales de vehículos.
        config(['lots.timezone' => 'America/Montevideo']);
        $actor = $this->actor(['can_view_poultry_houses', 'can_view_vehicle_fuel', 'can_view_vehicle_maintenance']);
        $unit = ProductionUnit::factory()->create();
        $house = PoultryHouse::factory()->for($unit)->create();
        $date = '2026-11-01';
        $time = Carbon::parse($date.'T12:00:00-03:00');
        Maintenance::factory()->create(['poultry_house_id' => $house->id, 'maintenance_date' => $date, 'cost_currency' => 'USD', 'cost_amount' => '10.50']);
        Maintenance::factory()->cancelled()->create(['poultry_house_id' => $house->id, 'maintenance_date' => $date]);
        Maintenance::factory()->create(['maintenance_date' => $date, 'cost_amount' => '30.00']);
        $vehicle = Vehicle::factory()->create();
        $delivery = Delivery::factory()->create(['production_unit_id' => $unit->id]);
        $usage = VehicleUsage::query()->create(['vehicle_id' => $vehicle->id, 'delivery_id' => $delivery->id, 'purpose' => 'Reparto',
            'started_at' => $time, 'vehicle_snapshot' => [], 'idempotency_key' => Str::uuid()->toString()]);
        foreach ([[$usage->id, '11.125'], [null, '3.000']] as [$usageId, $amount]) {
            VehicleFuelEntry::query()->create(['vehicle_id' => $vehicle->id, 'usage_id' => $usageId, 'occurred_at' => $time,
                'liters' => '1.000', 'fuel_type' => 'diesel', 'amount' => $amount, 'idempotency_key' => Str::uuid()->toString()]);
        }
        VehicleMaintenance::query()->create(['vehicle_id' => $vehicle->id, 'type' => 'corrective', 'status' => 'completed', 'completed_at' => $time,
            'description' => 'Servicio', 'cost_amount' => '6.875', 'idempotency_key' => Str::uuid()->toString(), 'request_hash' => hash('sha256', 'maintenance')]);
        Sanctum::actingAs($actor);

        // Consulta: incluye gastos generales en empresa y separa los importes de distinta moneda.
        $path = '/api/v1/gastos/resumen?desde='.$date.'&hasta='.$date;
        $this->getJson($path)->assertOk()->assertJsonPath('data.totales.0.moneda', 'USD')->assertJsonPath('data.totales.0.importe', '10.5000')
            ->assertJsonPath('data.totales.1.moneda', 'UYU')->assertJsonPath('data.totales.1.importe', '51.0000');
        $this->getJson($path.'&up_id='.$unit->id)->assertOk()->assertJsonPath('data.totales.0.importe', '10.5000')
            ->assertJsonPath('data.totales.1.importe', '11.125')->assertJsonPath('data.totales.1.por_fuente.0.fuente', 'combustible');

        // Mutación y consulta: una carga de otra UP vuelve general el combustible de ese reparto.
        DeliveryLoad::query()->create(['delivery_id' => $delivery->id, 'production_unit_id' => ProductionUnit::factory()->create()->id,
            'created_by' => $actor->id, 'quantity' => 1, 'idempotency_key' => Str::uuid()->toString()]);
        $this->getJson($path.'&up_id='.$unit->id)->assertOk()->assertJsonCount(1, 'data.totales')
            ->assertJsonPath('data.totales.0.moneda', 'USD');
        $this->getJson($path)->assertOk()->assertJsonPath('data.totales.1.importe', '51.0000');

        // Consulta: una cuenta sin permisos de vehículos recibe únicamente el gasto de galpones.
        Sanctum::actingAs($this->actor(['can_view_poultry_houses']));
        $this->getJson($path)->assertOk()->assertJsonPath('data.fuentes_sin_permiso', ['combustible', 'mantenimiento_vehiculos'])
            ->assertJsonPath('data.totales.1.importe', '30.0000');
    }

    // Flujo: todas las fuentes de negocio ejecutan detalle y consultas estándar con el contrato público.
    public function test_catalog_and_queries_are_executable(): void
    {
        // Preparación: habilita los módulos previos a la incorporación de vehículos.
        $actor = $this->actor(['can_view_reports', 'can_view_inventory', 'can_view_flocks', 'can_view_weighings', 'can_view_egg_collections', 'can_view_management_plans', 'can_view_delivery_history', 'can_view_egg_stock']);
        Sanctum::actingAs($actor);
        // Consulta: conserva las diez fuentes previas y rechaza las fuentes de vehículos sin permiso.
        $this->getJson('/api/v1/reportes/fuentes')->assertOk()->assertJsonCount(10, 'data');
        foreach (['vehicles.usage', 'vehicles.fuel', 'vehicles.maintenance'] as $sourceKey) {
            $this->postJson('/api/v1/reportes/'.$sourceKey.'/previsualizaciones')->assertForbidden();
        }

        // Preparación: concede los permisos funcionales de las tres fuentes nuevas.
        foreach (['can_view_vehicles', 'can_view_vehicle_fuel', 'can_view_vehicle_maintenance'] as $permission) {
            $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        // Acción: consulta las trece fuentes autorizadas y ejecuta todas sus consultas estándar.
        $this->getJson('/api/v1/reportes/fuentes')->assertOk()->assertJsonCount(13, 'data');
        foreach (app(ReportSourceRegistry::class)->all() as $source) {
            $this->postJson('/api/v1/reportes/'.$source->definition()->key.'/previsualizaciones')->assertOk();
            foreach ($source->definition()->standardQueries as $standard) {
                $this->postJson('/api/v1/reportes/'.$source->definition()->key.'/previsualizaciones', $standard['configuration'])->assertOk();
            }
        }
    }

    // Flujo: producción vitalicia conserva galpón del hecho, incluye lotes finalizados y excluye cancelaciones.
    public function test_lifetime_eggs_use_historical_house_and_allow_long_periods(): void
    {
        // Preparación: registra postura válida y cancelada en un lote finalizado.
        $flock = Flock::factory()->finished()->create();
        EggCollection::factory()->for($flock)->create(['quantity' => 120]);
        EggCollection::factory()->for($flock)->create(['quantity' => 30]);
        EggCollection::factory()->for($flock)->create(['quantity' => 900, 'status' => 'cancelled']);
        Sanctum::actingAs($this->actor(['can_view_reports', 'can_view_egg_collections']));
        // Acción: consulta todo el período agrupando por el galpón histórico.
        $response = $this->postJson('/api/v1/reportes/lots.egg-production/previsualizaciones', [
            'from' => '2020-01-01', 'to' => now()->toDateString(), 'groupings' => ['poultry_house'], 'metrics' => ['egg_count'],
        ])->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->assertSame('150', $response->json('data.rows.0.egg_count'));
        // Consulta: opciones históricas disponibles con permiso de reporte, sin permiso de edición de galpones.
        $this->getJson('/api/v1/reportes/lots.egg-production/opciones?field=poultry_house_id')->assertOk()->assertJsonCount(1, 'data');
    }

    // Flujo: alimentación convierte gramos a kilogramos sin sumar registros anulados.
    public function test_feed_totals_use_exact_units_and_exclude_cancellations(): void
    {
        // Preparación: suministra dos cantidades equivalentes en unidades distintas.
        $flock = Flock::factory()->create();
        $product = Product::factory()->create(['kind' => 'finished_feed']);
        FeedSupply::factory()->for($flock)->create(['product_id' => $product->id, 'quantity' => '1.250000', 'unit' => 'kg']);
        FeedSupply::factory()->for($flock)->create(['product_id' => $product->id, 'quantity' => '750.000000', 'unit' => 'g']);
        FeedSupply::factory()->for($flock)->create(['product_id' => $product->id, 'quantity' => '20.000000', 'status' => 'cancelled']);
        Sanctum::actingAs($this->actor(['can_view_reports', 'can_view_management_plans']));
        // Acción: agrega la cantidad exacta; la unidad se incorpora a la agrupación.
        $response = $this->postJson('/api/v1/reportes/management.feed-supply/previsualizaciones', [
            'groupings' => ['poultry_house'], 'metrics' => ['feed_quantity'],
        ])->assertOk()->assertJsonPath('data.rows.0.base_unit', 'kg');
        $this->assertSame(0, bccomp($response->json('data.rows.0.feed_quantity'), '2', 6));
    }

    // Flujo: rechaza cargas nuevas, conserva reintentos históricos y permite anular sin descontar otra vez.
    public function test_feed_registration_replay_and_cancel(): void
    {
        // Preparación: simula un suministro previo a la retirada y su operación idempotente histórica.
        $flock = Flock::factory()->create();
        $product = Product::factory()->create(['kind' => 'finished_feed']);
        $actor = $this->actor(['can_view_management_plans', 'can_execute_management_plans']);
        Sanctum::actingAs($actor);
        $body = ['idempotency_key' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => '10.500000', 'unit' => 'kg', 'occurred_at' => now()->startOfSecond()->toIso8601String()];
        $supply = FeedSupply::factory()->for($flock)->create(['product_id' => $product->id, 'quantity' => '10.500000', 'unit' => 'kg']);
        $currentBalance = FeedBalance::factory()->create(['production_unit_id' => $flock->production_unit_id,
            'quantity_grams' => 800000, 'minimum_quantity_grams' => 300000]);
        $snapshot = ['public_id' => $supply->public_id, 'quantity' => '10.500000', 'unit' => 'kg'];
        $historical = app(RunLotsCommand::class)->execute($actor, 'can_execute_management_plans', 'management-plans.feed.record',
            $body['idempotency_key'], ['flock' => $flock->public_id, ...$body], static function (string $operationId) use ($supply, $actor, $snapshot): array {
                app(AuditRecorder::class)->record(AuditEntryData::forSubject(
                    subject: $supply, actor: $actor, logName: 'management_plans', event: 'feed_supply_recorded',
                    description: 'Suministro histórico registrado antes de la retirada', operationId: $operationId,
                    properties: ['subject_snapshot' => $snapshot],
                ));

                return ['feed_supply' => $snapshot];
            });

        // Request: una clave nueva recibe la retirada explícita y no agrega suministros.
        $url = '/api/v1/flocks/'.$flock->public_id.'/alimentacion';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, array_diff_key($body, ['idempotency_key' => true]))
            ->assertGone()->assertJsonPath('status', 410);

        // Requests: una operación ya guardada puede reproducirse sin ejecutar otra carga.
        $first = $this->withHeader('Idempotency-Key', $body['idempotency_key'])->postJson($url, $body)->assertCreated()
            ->assertJsonPath('data.feed_supply.quantity', '10.500000')
            ->assertJsonPath('data.feed_supply.unit', 'kg');
        $this->assertSame($historical->operation_id, $first->json('data.operation_id'));
        $first->assertJsonPath('data.feed_supply.public_id', $supply->public_id);
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.operation_id', $first->json('data.operation_id'));
        $this->assertDatabaseCount('feed_supplies', 1);
        // Acción: anula el registro con una nueva operación auditada.
        $id = $first->json('data.feed_supply.public_id');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url.'/'.$id.'/anulacion', ['reason' => 'Cantidad incorrecta'])
            ->assertOk()->assertJsonPath('data.feed_supply.public_id', $id)->assertJsonPath('data.feed_supply.status', 'cancelled');
        $this->assertDatabaseHas('feed_supplies', ['public_id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('activity_log', ['event' => 'feed_supply_recorded']);
        $this->assertDatabaseHas('activity_log', ['event' => 'feed_supply_cancelled']);
        $this->assertDatabaseCount('activity_log', 2);
        $this->assertDatabaseCount('feed_consumptions', 0);
        $this->assertSame(800000, $currentBalance->fresh()->quantity_grams);
        $this->assertSame(300000, $currentBalance->fresh()->minimum_quantity_grams);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: una donación independiente descuenta stock y aparece una sola vez en el reporte.
    public function test_donation_affects_stock_and_report_without_duplicate_replay(): void
    {
        // Preparación: registra stock de huevos y autentica permisos funcionales.
        $actor = $this->actor(['can_move_egg_stock', 'can_view_egg_stock', 'can_move_inventory', 'can_view_reports', 'can_view_delivery_history']);
        $unit = ProductionUnit::factory()->create();
        app(RecordManualEggStockAction::class)->execute($unit, ['idempotency_key' => (string) Str::uuid(), 'quantity' => 100, 'reason' => 'Ingreso'], $actor);
        Sanctum::actingAs($actor);
        $body = ['idempotency_key' => (string) Str::uuid(), 'quantity' => 15, 'reason' => 'Donación a escuela', 'occurred_at' => now()->subSecond()->toIso8601String()];
        // Acción: registra y repite una donación.
        $url = '/api/v1/unidades-productivas/'.$unit->id.'/donaciones';
        $first = $this->withHeader('Idempotency-Key', $body['idempotency_key'])->postJson($url, $body)->assertCreated();
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.transaction', $first->json('data.transaction'));
        // Consulta: verifica saldo y totales vendidos/donados sin confundir ingresos.
        $this->getJson('/api/v1/production-units/'.$unit->id.'/egg-stock')->assertOk()->assertJsonPath('data.balance', 85);
        $this->postJson('/api/v1/reportes/commerce.egg-outflow/previsualizaciones', ['metrics' => ['sold_eggs', 'donated_eggs', 'total_eggs']])
            ->assertOk()->assertJsonPath('data.rows.0.sold_eggs', '0')->assertJsonPath('data.rows.0.donated_eggs', '15')->assertJsonPath('data.rows.0.total_eggs', '15');
    }

    // Flujo: las fechas incompatibles con stock actual se rechazan de forma explícita.
    public function test_current_stock_rejects_historical_dates(): void
    {
        // Preparación: autentica lectura de inventario.
        Sanctum::actingAs($this->actor(['can_view_reports', 'can_view_inventory']));
        // Acción: intenta consultar saldos a una fecha histórica inexistente en esta fuente.
        $this->postJson('/api/v1/reportes/inventory.stock-balances/previsualizaciones', ['from' => '2020-01-01'])->assertUnprocessable();
    }

    // Flujo: la pauta repite el total diario sólo en días sin carga y recorta el período consultado.
    public function test_feed_estimates_are_separate_and_clip_missing_days(): void
    {
        // Preparación: fija el calendario y registra cantidades diarias diferentes.
        $this->travelTo(Carbon::parse('2026-10-12T12:00:00-03:00'));
        $flock = Flock::factory()->create();
        $product = Product::factory()->create(['kind' => 'finished_feed']);
        FeedSupply::factory()->for($flock)->create(['product_id' => $product->id, 'quantity' => '10', 'occurred_at' => '2026-10-08T12:00:00-03:00']);
        FeedSupply::factory()->for($flock)->create(['product_id' => $product->id, 'quantity' => '20', 'occurred_at' => '2026-10-11T12:00:00-03:00']);
        Sanctum::actingAs($this->actor(['can_view_reports', 'can_view_management_plans']));
        // Consulta: 30 registrados; días 9 y 10 con 10, día 12 con 20 estimados.
        $response = $this->postJson('/api/v1/reportes/management.feed-supply/previsualizaciones', ['metrics' => ['feed_quantity', 'estimated_feed_quantity']])->assertOk();
        $this->assertSame(0, bccomp($response->json('data.rows.0.feed_quantity'), '30', 6));
        $this->assertSame(0, bccomp($response->json('data.rows.0.estimated_feed_quantity'), '40', 6));
        // Consulta: recorta un tramo interior sin repetir registros reales fuera del rango.
        $clipped = $this->postJson('/api/v1/reportes/management.feed-supply/previsualizaciones', ['from' => '2026-10-10', 'to' => '2026-10-11', 'metrics' => ['feed_quantity', 'estimated_feed_quantity']])->assertOk();
        $this->assertSame(0, bccomp($clipped->json('data.rows.0.feed_quantity'), '20', 6));
        $this->assertSame(0, bccomp($clipped->json('data.rows.0.estimated_feed_quantity'), '10', 6));
    }

    private function actor(array $permissions): User
    {
        $actor = User::factory()->create();
        foreach ($permissions as $permission) {
            $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    }

    // Flujo: combina muestras individuales y grupales ponderando por las aves representadas.
    public function test_weighing_average_is_weighted_across_legacy_and_daily_records(): void
    {
        // Preparación: conserva un grupo histórico de tres aves y uno diario de cinco.
        $flock = Flock::factory()->create();
        $legacy = Weighing::factory()->for($flock)->create(['mode' => 'group', 'represented_bird_count' => 3, 'total_weight_g' => '3000', 'average_weight_g' => '1000']);
        WeighingMeasurement::factory()->create(['weighing_id' => $legacy->id, 'weight_g' => null, 'bird_count' => 3, 'total_weight_g' => '3000', 'average_weight_g' => '1000']);
        $daily = new DailyWeighing;
        $daily->forceFill(['flock_id' => $flock->id, 'local_date' => now()->toDateString()])->save();
        $entry = new DailyWeighingEntry;
        $entry->forceFill(['daily_weighing_id' => $daily->id, 'mode' => 'group', 'bird_count' => 5, 'total_weight_g' => '10000', 'average_weight_g' => '2000', 'occurred_at' => now(), 'created_by' => User::factory()->create()->id])->save();
        Sanctum::actingAs($this->actor(['can_view_reports', 'can_view_weighings']));
        // Consulta: promedio correcto 13000/8, sin promediar 1000 y 2000 directamente.
        $result = $this->postJson('/api/v1/reportes/lots.weighing-measurements/previsualizaciones', ['metrics' => ['weighted_average_g', 'represented_birds']])->assertOk();
        $this->assertSame(0, bccomp($result->json('data.rows.0.weighted_average_g'), '1625', 6));
        $this->assertSame(8, $result->json('data.rows.0.represented_birds'));
    }

    // Flujo: no continúa la pauta de un lote cerrado ni estima antes del primer dato real.
    public function test_feed_estimates_stop_before_the_closure_day(): void
    {
        // Preparación: último suministro el día ocho y cierre el día diez.
        $this->travelTo(Carbon::parse('2026-10-12T12:00:00-03:00'));
        $flock = Flock::factory()->finished()->create(['finalized_at' => '2026-10-10T12:00:00-03:00']);
        FeedSupply::factory()->for($flock)->create(['quantity' => '10', 'occurred_at' => '2026-10-08T12:00:00-03:00']);
        Sanctum::actingAs($this->actor(['can_view_reports', 'can_view_management_plans']));
        // Consulta: solamente el día nueve tiene una pauta estimada de diez kg.
        $response = $this->postJson('/api/v1/reportes/management.feed-supply/previsualizaciones', ['metrics' => ['feed_quantity', 'estimated_feed_quantity']])->assertOk();
        $this->assertSame(0, bccomp($response->json('data.rows.0.feed_quantity'), '10', 6));
        $this->assertSame(0, bccomp($response->json('data.rows.0.estimated_feed_quantity'), '10', 6));
    }

    // Flujo: todos los paths OpenAPI de reporting resuelven una ruta pública real.
    public function test_openapi_paths_match_registered_routes(): void
    {
        // Preparación: carga el contrato versionado y sus métodos HTTP.
        $contract = Yaml::parseFile(base_path('contracts/openapi/reporting.yaml'));
        $operations = Yaml::parseFile(base_path('contracts/openapi/reporting-operations.yaml'));
        $contract['paths'] = [...$contract['paths'], ...$operations['paths']];
        foreach ($contract['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (! in_array($method, ['get', 'post', 'patch', 'delete'], true)) {
                    continue;
                }
                // Consulta: verifica el enrutamiento sin ejecutar ni mutar recursos.
                $uri = preg_replace('/\{[^}]+\}/', '1', $path);
                $route = Route::getRoutes()->match(Request::create('/api/v1'.$uri, strtoupper($method)));
                $this->assertNotNull($route->getActionName());
            }
        }
    }
}
