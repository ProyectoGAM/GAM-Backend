<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Models\CustomersAndAccounts\CustomerAccount;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Models\ManagementPlans\ManagementExecutionCorrection;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\SharedDevice;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleMaintenance;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\ReportingAndAnalytics\ReportExportWriter;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSnapshotStore;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Lots\LotsTestCase;

final class DedicatedBusinessReportsTest extends LotsTestCase
{
    private const array PERMISSIONS = ['can_view_reports', 'can_export_reports', 'can_view_mortality', 'can_view_management_plans',
        'can_view_feed_stock', 'can_view_customer_accounts', 'can_view_poultry_houses', 'can_view_vehicle_maintenance'];

    // Flujo: bajas y medicaciones reutilizan hechos del lote, conservan historia y excluyen correcciones.
    public function test_lot_reports_preserve_historical_facts_and_exclude_cancelled_actions(): void
    {
        // Preparación: mezcla hechos válidos, anulados y fechas externas al período.
        $this->travelTo(Carbon::parse('2026-11-03T12:00:00-03:00'));
        $this->signIn(self::PERMISSIONS);
        $flock = Flock::factory()->finished()->create();
        MortalityRecord::factory()->for($flock)->create(['quantity' => 3, 'occurred_at' => '2026-11-01T23:59:59-03:00']);
        MortalityRecord::factory()->for($flock)->create(['quantity' => 99, 'status' => 'cancelled', 'occurred_at' => '2026-11-01T12:00:00-03:00']);
        MortalityRecord::factory()->for($flock)->create(['quantity' => 88, 'occurred_at' => '2026-11-02T00:00:00-03:00']);
        $attributes = ['poultry_house_id' => $flock->poultry_house_id, 'production_unit_id' => $flock->production_unit_id,
            'occurred_at' => '2026-11-01T00:00:00-03:00', 'responsible_name_snapshot' => 'Responsable histórico', 'medicine_name_snapshot' => 'Medicamento histórico'];
        MedicineApplication::factory()->for($flock)->create($attributes);
        $cancelled = MedicineApplication::factory()->for($flock)->create($attributes);
        ManagementExecutionCorrection::factory()->for($flock)->create(['original_type' => 'medication', 'original_public_id' => $cancelled->public_id]);

        // Consulta: el día local conserva los dos extremos y las cantidades desconocidas permanecen nulas.
        $period = ['from' => '2026-11-01', 'to' => '2026-11-01', 'filters' => [['field' => 'flock_id', 'operator' => 'eq', 'value' => $flock->public_id]]];
        $this->postJson('/api/v1/reportes/lots.mortality/previsualizaciones', [...$period, 'groupings' => ['month', 'flock'], 'metrics' => ['mortality_count']])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.mortality_count', '3');
        $this->postJson('/api/v1/reportes/lots.medications/previsualizaciones', $period)->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.medicine', 'Medicamento histórico')->assertJsonPath('data.rows.0.responsible', 'Responsable histórico')
            ->assertJsonPath('data.rows.0.quantity', null)->assertJsonPath('data.rows.0.poultry_house', PoultryHouse::query()->findOrFail($flock->poultry_house_id)->name);
    }

    // Flujo: la cuenta completa incluye movimientos ajenos a repartos y sus reversiones con signo.
    public function test_customer_account_report_includes_the_full_signed_ledger(): void
    {
        // Preparación: conserva cargo, cobro, reversión y ajuste sin reparto, con saldo actual independiente.
        $this->signIn(self::PERMISSIONS);
        $account = CustomerAccount::factory()->create(['balance' => '90.000']);
        CustomerAccountMovement::factory()->create(['customer_account_id' => $account->id, 'sequence' => 1,
            'type' => 'sale_charge', 'amount' => '100', 'balance_before' => '0', 'balance_after' => '100', 'payment_method' => null,
            'occurred_at' => '2026-11-01T00:00:00-03:00']);
        $payment = CustomerAccountMovement::factory()->create(['customer_account_id' => $account->id, 'sequence' => 2,
            'amount' => '-30', 'balance_before' => '100', 'balance_after' => '70', 'payment_method' => 'check',
            'occurred_at' => '2026-11-01T23:59:59-03:00']);
        CustomerAccountMovement::factory()->create(['customer_account_id' => $account->id, 'sequence' => 3,
            'type' => 'reversal', 'amount' => '30', 'balance_before' => '70', 'balance_after' => '100', 'reverses_movement_id' => $payment->id, 'reason' => 'Cobro duplicado',
            'occurred_at' => '2026-11-02T00:00:00-03:00']);
        CustomerAccountMovement::factory()->create(['customer_account_id' => $account->id, 'sequence' => 4,
            'type' => 'adjustment_decrease', 'amount' => '-10', 'balance_before' => '100', 'balance_after' => '90', 'reason' => 'Ajuste por recuento',
            'occurred_at' => '2026-11-02T12:00:00-03:00']);

        // Consulta: el total neto incluye la compensación y el corte histórico no modifica el saldo actual.
        $path = '/api/v1/reportes/accounts.movements/previsualizaciones';
        $this->postJson($path, ['metrics' => ['net_amount', 'movement_count']])->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.net_amount', '90.000')->assertJsonPath('data.rows.0.movement_count', 4)
            ->assertJsonPath('data.rows.0.currency', 'UYU');
        $this->postJson($path, ['from' => '2026-11-01', 'to' => '2026-11-01'])->assertOk()->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.payment_method', 'Cheque')->assertJsonPath('data.rows.0.balance_after', '70.000')
            ->assertJsonPath('data.rows.0.current_balance', '90.000')->assertJsonPath('data.rows.0.delivery_id', null);
        $this->postJson($path, ['columns' => ['reverses_movement_id'], 'filters' => [['field' => 'type', 'operator' => 'eq', 'value' => 'Reversión']]])
            ->assertOk()->assertJsonPath('data.rows.0.reverses_movement_id', $payment->public_id);
        $this->assertFalse(app(ReportSourceRegistry::class)->get('accounts.movements')->definition()->supportsPublicLinks);
    }

    // Flujo: elaborar y cancelar generan una sola fila de producción sin multiplicarla por ingredientes o plantas.
    public function test_manufacturing_report_keeps_snapshots_and_excludes_cancellations_from_effective_totals(): void
    {
        // Preparación: una elaboración válida y otra cancelada tienen dos ingredientes y dos plantas.
        $this->signIn(self::PERMISSIONS);
        $snapshot = ['recipe_name' => 'Receta histórica', 'plant_name' => 'Planta original',
            'ingredients' => [['name' => 'Maíz', 'required_quantity_grams' => '700'], ['name' => 'Soja', 'required_quantity_grams' => '300']],
            'distribution' => [['plant_name' => 'Planta A', 'quantity_grams' => '600'], ['plant_name' => 'Planta B', 'quantity_grams' => '400']],
            'shortages' => [['quantity_grams' => '100']]];
        $valid = FeedStockOperation::factory()->create(['kind' => 'manufacture', 'quantity_grams' => 1251, 'snapshot' => $snapshot, 'occurred_at' => '2026-11-01T12:00:00-03:00']);
        $cancelled = FeedStockOperation::factory()->create(['kind' => 'manufacture', 'quantity_grams' => 2000, 'snapshot' => $snapshot, 'occurred_at' => '2026-11-01T12:00:00-03:00']);
        FeedStockOperation::factory()->create(['kind' => 'cancellation', 'cancels_operation_id' => $cancelled->id,
            'production_unit_id' => $cancelled->production_unit_id, 'quantity_grams' => -2000]);

        // Consulta: conserva dos filas y suma sólo la elaboración vigente con precisión de gramos.
        $path = '/api/v1/reportes/inventory.feed-manufacturing/previsualizaciones';
        $response = $this->postJson($path, ['filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'Elaborada']]])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.feed_recipe', 'Receta histórica')
            ->assertJsonPath('data.rows.0.poultry_house', 'Planta original');
        $this->assertSame(0, bccomp($response->json('data.rows.0.quantity'), '1.251', 6));
        $this->assertStringContainsString('Maíz', $response->json('data.rows.0.ingredients'));
        $this->assertStringContainsString('Planta B', $response->json('data.rows.0.distribution'));
        $total = $this->postJson($path, ['from' => '2026-11-01', 'to' => '2026-11-01', 'metrics' => ['effective_quantity', 'manufacturing_count']])
            ->assertOk()->assertJsonPath('data.rows.0.manufacturing_count', 2);
        $this->assertSame(0, bccomp($total->json('data.rows.0.effective_quantity'), '1.251', 6));
        $this->assertSame(1251, $valid->fresh()->quantity_grams);
    }

    // Flujo: el stock de alimento expone mínimos y negativos actuales sin aparentar un saldo histórico.
    public function test_feed_stock_report_preserves_negative_balances_and_minimum_states(): void
    {
        // Preparación: una cuenta negativa, una en mínimo y otra sin mínimo configurado.
        $this->signIn(self::PERMISSIONS);
        FeedBalance::factory()->create(['feed_recipe_id' => FeedRecipe::factory()->create(['status' => 'inactive'])->id, 'quantity_grams' => -1251, 'minimum_quantity_grams' => 0]);
        FeedBalance::factory()->create(['quantity_grams' => 2000, 'minimum_quantity_grams' => 2000]);
        FeedBalance::factory()->create(['quantity_grams' => 3000, 'minimum_quantity_grams' => null]);

        // Consulta: igualdad y saldo inferior requieren atención; el mínimo nulo conserva su significado.
        $path = '/api/v1/reportes/inventory.feed-balances/previsualizaciones';
        $this->postJson($path, ['filters' => [['field' => 'attention', 'operator' => 'eq', 'value' => true]]])->assertOk()->assertJsonCount(2, 'data.rows');
        $response = $this->postJson($path, ['filters' => [['field' => 'quantity', 'operator' => 'lt', 'value' => '0']]])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.status', 'Por debajo del mínimo');
        $this->assertSame(0, bccomp($response->json('data.rows.0.quantity'), '-1.251', 6));
        $this->postJson($path, ['filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'Sin configurar']]])
            ->assertOk()->assertJsonPath('data.rows.0.minimum_quantity', null)->assertJsonPath('data.rows.0.attention', false);
        $this->postJson($path, ['date_preset' => 'last_month'])->assertUnprocessable();
        $this->postJson($path, ['groupings' => ['month']])->assertUnprocessable();
    }

    // Flujo: los gastos se agrupan por moneda y no suman intervenciones canceladas o pendientes.
    public function test_maintenance_expenses_separate_currencies_and_exclude_non_effective_costs(): void
    {
        // Preparación: combina gastos válidos en USD/UYU con anulaciones de galpón y planes vehiculares.
        $this->signIn(self::PERMISSIONS);
        Maintenance::factory()->create(['maintenance_date' => '2026-11-01', 'cost_amount' => '10.125', 'cost_currency' => 'UYU']);
        Maintenance::factory()->create(['maintenance_date' => '2026-11-01', 'cost_amount' => '2.500', 'cost_currency' => 'USD']);
        Maintenance::factory()->cancelled()->create(['maintenance_date' => '2026-11-01', 'cost_amount' => '999']);
        $vehicle = Vehicle::factory()->create();
        foreach ([['completed', '5.125', 'UYU'], ['completed', '1.500', 'USD'], ['planned', '900', 'UYU'], ['cancelled', '800', 'UYU']] as [$status, $cost, $currency]) {
            VehicleMaintenance::query()->create(['vehicle_id' => $vehicle->id, 'type' => 'corrective', 'status' => $status, 'completed_at' => $status === 'completed' ? '2026-11-01T12:00:00-03:00' : null,
                'description' => 'Servicio', 'cost_amount' => $cost, 'cost_currency' => $currency, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', (string) Str::uuid())]);
        }

        // Consulta: el normalizador incorpora la moneda aunque el cliente no la haya pedido al agrupar.
        foreach (['expenses.poultry-house-maintenance' => ['2.5000', '10.1250'], 'vehicles.maintenance' => ['1.500', '5.125']] as $key => [$usd, $uyu]) {
            $response = $this->postJson('/api/v1/reportes/'.$key.'/previsualizaciones', ['metrics' => ['cost_total'], 'sorts' => [['field' => 'cost_currency', 'direction' => 'asc']]])->assertOk()->assertJsonCount(2, 'data.rows')
                ->assertJsonPath('data.rows.0.cost_currency', 'USD')->assertJsonPath('data.rows.1.cost_currency', 'UYU');
            $this->assertSame(0, bccomp($response->json('data.rows.0.cost_total'), $usd, 6));
            $this->assertSame(0, bccomp($response->json('data.rows.1.cost_total'), $uyu, 6));
        }
        $sources = collect($this->getJson('/api/v1/reportes/fuentes')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('expenses', $sources['vehicles.maintenance']['module']['key']);
        $this->assertSame('expenses', $sources['expenses.poultry-house-maintenance']['module']['key']);
        $this->assertSame('lots', $sources['lots.medications']['module']['key']);
    }

    // Flujo: las fuentes respetan permisos y límites de UP también en opciones, agregados y exportaciones.
    public function test_dedicated_reports_enforce_permissions_and_shared_unit_boundaries(): void
    {
        // Solicitud: exige autenticación y los permisos propios de cada fuente nueva.
        $keys = ['lots.mortality', 'lots.medications', 'accounts.movements', 'inventory.feed-manufacturing', 'inventory.feed-balances', 'expenses.poultry-house-maintenance'];
        $this->postJson('/api/v1/reportes/lots.mortality/previsualizaciones')->assertUnauthorized();
        $this->signIn(['can_view_reports']);
        foreach ($keys as $key) {
            $this->postJson('/api/v1/reportes/'.$key.'/previsualizaciones')->assertForbidden();
        }
        $actor = $this->signIn(self::PERMISSIONS);
        $own = Flock::factory()->create();
        $other = Flock::factory()->create();
        foreach ([$own, $other] as $flock) {
            MortalityRecord::factory()->for($flock)->create(['quantity' => 3]);
            MedicineApplication::factory()->for($flock)->create(['poultry_house_id' => $flock->poultry_house_id, 'production_unit_id' => $flock->production_unit_id]);
            FeedStockOperation::factory()->create(['kind' => 'manufacture', 'production_unit_id' => $flock->production_unit_id]);
            FeedBalance::factory()->create(['production_unit_id' => $flock->production_unit_id, 'quantity_grams' => 5000]);
            Maintenance::factory()->create(['poultry_house_id' => $flock->poultry_house_id]);
        }
        config(['identity.pin.pepper' => 'test-only-temporary-value']);
        $employee = User::factory()->devicePin(ProductionUnit::query()->findOrFail($own->production_unit_id))->create();
        $employee->syncRoles([$actor->roles()->firstOrFail()]);
        $employee->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_login_shared', 'web'));
        $secret = Str::random(64);
        $device = SharedDevice::query()->create(['name' => 'Reportes', 'production_unit_id' => $employee->production_unit_id, 'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(), 'enrolled_by' => $actor->id, 'enrolled_at' => now()]);
        $token = $employee->createToken('shared-reports', ['*'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($employee, $device->refresh(), $token);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Shared-Device-Token' => $device->id.'|'.$secret, 'X-GAM-Session' => $session->id];
        $this->app['auth']->forgetGuards();

        // Consulta: el dispositivo ve una UP y no recibe la cuenta global ni mantenimientos vehiculares globales.
        foreach (array_diff($keys, ['accounts.movements']) as $key) {
            $base = '/api/v1/reportes/'.$key;
            $this->postJson($base.'/previsualizaciones', ['columns' => ['production_unit_id']], $headers)->assertOk()->assertJsonCount(1, 'data.rows')
                ->assertJsonPath('data.rows.0.production_unit_id', $own->production_unit_id);
            $this->getJson($base.'/opciones?field=production_unit_id', $headers)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.value', $own->production_unit_id);
            $metric = match ($key) {
                'lots.mortality' => 'mortality_count', 'lots.medications' => 'application_count',
                'inventory.feed-manufacturing' => 'manufacturing_count', 'inventory.feed-balances' => 'available_stock',
                default => 'maintenance_count',
            };
            $total = $this->postJson($base.'/previsualizaciones', ['metrics' => [$metric]], $headers)->assertOk()->assertJsonCount(1, 'data.rows');
            $expected = match ($key) {
                'lots.mortality' => '3', 'inventory.feed-balances' => '5', default => '1'
            };
            $this->assertSame(0, bccomp((string) $total->json('data.rows.0.'.$metric), $expected, 6));
        }
        foreach (['accounts.movements', 'vehicles.maintenance'] as $key) {
            $this->postJson('/api/v1/reportes/'.$key.'/previsualizaciones', [], $headers)->assertForbidden();
        }
    }

    // Flujo: una fuente dedicada exporta y conserva sus filas para XLSX, PDF y la vista web.
    public function test_dedicated_report_exports_share_the_same_snapshot(): void
    {
        // Preparación: conserva una baja conocida con permisos de exportación.
        Storage::fake('local');
        $actor = $this->signIn(self::PERMISSIONS);
        $flock = Flock::factory()->create();
        MortalityRecord::factory()->for($flock)->create(['quantity' => 7]);
        $source = app(ReportSourceRegistry::class)->get('lots.mortality');
        $query = app(ReportQueryNormalizer::class)->normalize('lots.mortality', ['columns' => ['flock', 'quantity']]);
        foreach (['xlsx', 'pdf'] as $format) {
            // Exportación: genera archivos reales y comprueba la instantánea común de presentación.
            $export = ReportExport::factory()->for($actor)->create(['source_key' => 'lots.mortality', 'format' => $format, 'query' => $query->toArray()]);
            $file = app(ReportExportWriter::class)->write($export, $source, $query);
            $export->update($file);
            if ($format === 'xlsx') {
                $this->assertSame([['Lote' => $flock->code, 'Aves fallecidas' => 7]], SimpleExcelReader::create(Storage::disk('local')->path($file['path']))->getRows()->all());
            } else {
                $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($file['path']));
            }
            $this->assertSame('7', app(ReportSnapshotStore::class)->page($export, 1)['result']->rows[0]['quantity']);
        }
    }
}
