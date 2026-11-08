<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Actions\FarmStructure\ChangeProductionUnitStatusAction;
use App\Actions\IdentityAndAccess\GeneratePairingCodeAction;
use App\Actions\IdentityAndAccess\UpdateUserProductionUnitAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\Role;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityRecord;
use App\Models\Notifications\OperationalAlert;
use App\Models\SharedDevice;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Queries\ManagementPlans\ListFlockManagementHistoryQuery;
use App\Services\IdentityAndAccess\PinHasher;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Inventory\InventoryStockBalancesReportSource;
use App\Services\Lots\EggProductionReportSource;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductionUnitIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.pin.pepper' => 'unit-test-pepper']);
    }

    /** El selector no mezcla UP, modalidades ni cuentas Sin rol. */
    public function test_picker_and_login_require_user_device_unit_match(): void
    {
        // Prepara usuarios de dos UP y una cuenta no habilitada.
        [$user, $device, $secret, $admin] = $this->fixture();
        $other = User::factory()->devicePin()->create();
        $other->syncRoles('employee');
        $unassigned = User::factory()->devicePin($user->productionUnit)->create();
        $unassigned->syncRoles('unassigned');
        // Consulta elegibles e intenta saltar el filtro enviando IDs manuales.
        $this->withHeader('X-Shared-Device-Token', $secret)->getJson('/api/v1/shared-device/users')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);
        foreach ([$other, $unassigned, $admin] as $rejected) {
            $this->postJson('/api/v1/shared-device/login-pin', ['user_id' => $rejected->id, 'pin' => '0007'])->assertUnauthorized();
        }
        $this->assertSame(0, $other->fresh()->pin_failed_attempts);
        $this->assertDatabaseCount('auth_sessions', 0);
    }

    /** El acceso PIN limita listados y bindings incluso con permisos funcionales. */
    public function test_pin_session_lists_only_own_unit_and_denies_foreign_ids(): void
    {
        // Crea recursos propios y ajenos antes de autenticar.
        [$user, $device, $secret] = $this->fixture();
        $other = ProductionUnit::factory()->create();
        $house = PoultryHouse::factory()->create(['production_unit_id' => $other->id]);
        $this->login($user, $secret);
        // Comprueba filtrado, acceso directo y manipulación de filtros.
        $this->getJson('/api/v1/production-units')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->production_unit_id);
        $this->getJson('/api/v1/production-units/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/poultry-houses/'.$house->id)->assertNotFound();
        $this->getJson('/api/v1/inventory/balances?production_unit_id='.$other->id)->assertNotFound();
        $this->getJson('/api/v1/reference/options')->assertOk()->assertJsonMissing(['value' => $other->id, 'label' => $other->name]);
    }

    // Flujo: los agregados PIN usan la UP del dispositivo aunque el cliente omita el filtro.
    public function test_pin_summary_queries_scope_before_aggregating(): void
    {
        // Preparación: crea hechos propios, ajenos y una alerta de alcance general.
        [$user, , $secret] = $this->fixture();
        Role::findByName('employee', 'web')->givePermissionTo(['can_view_flocks', 'can_view_mortality', 'can_view_egg_collections', 'can_view_notifications']);
        $ownHouse = PoultryHouse::factory()->create(['production_unit_id' => $user->production_unit_id]);
        $own = Flock::factory()->create(['poultry_house_id' => $ownHouse->id, 'current_quantity' => 25]);
        $foreign = Flock::factory()->create(['current_quantity' => 75]);
        $occurredAt = now(config('lots.timezone'))->startOfDay()->addHour();
        foreach ([[$own, 2], [$foreign, 7]] as [$flock, $quantity]) {
            MortalityRecord::factory()->for($flock)->create(['quantity' => $quantity, 'occurred_at' => $occurredAt]);
            EggCollection::factory()->for($flock)->create(['quantity' => $quantity, 'occurred_at' => $occurredAt]);
        }
        foreach ([$own->production_unit_id, $foreign->production_unit_id, null] as $unitId) {
            OperationalAlert::factory()->create(['production_unit_id' => $unitId, 'source_key' => 'condition',
                'type' => 'poultry_house_over_capacity', 'view_permission' => 'can_view_poultry_houses', 'entity_type' => 'poultry_house',
                'entity_id' => 'condition', 'urgency' => 'alto', 'title' => 'Alerta de prueba', 'occurred_at' => $occurredAt]);
        }
        $this->login($user, $secret);

        // Consulta: comprueba los totales y rechaza seleccionar otra UP explícitamente.
        $date = $occurredAt->toDateString();
        $mortality = '/api/v1/mortalidades/resumen?desde='.$date.'&hasta='.$date;
        $this->getJson($mortality)->assertOk()->assertJsonPath('data.up_id', $own->production_unit_id)->assertJsonPath('data.bajas_registradas', 2);
        $this->getJson($mortality.'&up_id='.$foreign->production_unit_id)->assertNotFound();
        $this->getJson('/api/v1/lotes/resumen')->assertOk()->assertJsonPath('data.aves_vivas', 25);
        $this->getJson('/api/v1/lotes/resumen?up_id='.$foreign->production_unit_id)->assertNotFound();
        $this->getJson('/api/v1/collections/metrics?comparar_anterior=1&date_from='.$date.'&date_to='.$date)->assertOk()->assertJsonPath('data.total_eggs', 2);
        $this->getJson('/api/v1/alertas-operativas/resumen')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/alertas-operativas?up_id='.$foreign->production_unit_id)->assertNotFound();
        $this->getJson('/api/v1/gastos/resumen?desde='.$date.'&hasta='.$date)->assertOk()->assertJsonPath('data.up_id', $own->production_unit_id);
    }

    /** Un PAT compartido no funciona sin la credencial física y generación. */
    public function test_shared_token_requires_device_and_session_on_every_endpoint(): void
    {
        // Autentica y retira las pruebas del dispositivo para una ruta común.
        [$user, $device, $secret] = $this->fixture();
        $this->login($user, $secret);
        $this->withoutHeader('X-GAM-Session')->getJson('/api/v1/me')->assertUnauthorized();
    }

    /** Cambiar el PIN cierra sesiones pero mantiene la vinculación de la tablet. */
    public function test_own_pin_change_revokes_session_and_preserves_device(): void
    {
        // Abre la sesión PIN y solicita el cambio con el PIN actual.
        [$user, $device, $secret] = $this->fixture();
        $this->login($user, $secret);
        $this->patchJson('/api/v1/auth/pin', ['current_pin' => '0007', 'pin' => '0012', 'pin_confirmation' => '0012'])
            ->assertOk()->assertJsonPath('requiere_reingreso', true);
        // Verifica persistencia y revocación sin perder la vinculación.
        $this->assertTrue(app(PinHasher::class)->check('0012', $user->fresh()->pin_hash));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
        $this->assertNull($device->fresh()->revoked_at);
    }

    /** El código conserva la UP elegida por Admin y sólo puede consumirse una vez. */
    public function test_pairing_binds_server_selected_unit_and_rejects_replay(): void
    {
        // Genera el código con el administrador y canjea sin aceptar otra UP del cliente.
        [$user, $device, $secret, $admin] = $this->fixture();
        $code = app(GeneratePairingCodeAction::class)->execute($admin, 'Tablet UP', $user->production_unit_id)['code'];
        $response = $this->postJson('/api/v1/shared-devices/pairing', ['code' => $code, 'device_name' => 'Tablet nueva'])->assertCreated();
        $response->assertJsonPath('device.up_id', $user->production_unit_id);
        $this->postJson('/api/v1/shared-devices/pairing', ['code' => $code, 'device_name' => 'Repetida'])->assertUnprocessable();
    }

    /** Cambiar la UP del usuario invalida sesiones y no modifica su modalidad. */
    public function test_user_transfer_revokes_session_without_converting_access_method(): void
    {
        // Prepara la nueva UP e inicia la sesión compartida.
        [$user, $device, $secret, $admin] = $this->fixture();
        $other = ProductionUnit::factory()->create();
        $this->login($user, $secret);
        // Simula la operación administrativa fuera del contexto del empleado.
        app(ProductionUnitAccess::class)->within(null,
            fn () => app(UpdateUserProductionUnitAction::class)->execute($admin, $user, $other->id));
        $this->assertSame($other->id, $user->fresh()->production_unit_id);
        $this->assertTrue($user->fresh()->usesDevicePin());
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
    }

    /** Los recursos globales y la administración no se habilitan desde una sesión restringida. */
    public function test_global_business_mutations_are_forbidden_even_with_permissions(): void
    {
        // Autentica un empleado con permisos amplios de negocio.
        [$user, $device, $secret] = $this->fixture();
        $this->login($user, $secret);
        // Deniega cambios globales antes de validar sus datos.
        $this->postJson('/api/v1/products', [])->assertForbidden();
        $this->getJson('/api/v1/clientes')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    /** Un traslado entre ubicaciones de distintas UP no produce ningún movimiento parcial. */
    public function test_inventory_transfer_rejects_foreign_destination_without_changing_stock(): void
    {
        // Prepara origen propio, destino ajeno y existencias iniciales.
        [$user, $device, $secret] = $this->fixture();
        $product = Product::factory()->create();
        $from = StockLocation::factory()->create(['production_unit_id' => $user->production_unit_id]);
        $to = StockLocation::factory()->create(['production_unit_id' => ProductionUnit::factory()->create()->id]);
        $balance = StockBalance::factory()->create(['stock_location_id' => $from->id, 'product_id' => $product->id, 'on_hand_quantity' => '10.000000']);
        $this->login($user, $secret);
        // Intenta transferir a la UP ajena mediante un ID enviado directamente.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/inventory/transfers', [
            'lines' => [['product_id' => $product->id, 'from_stock_location_id' => $from->id, 'to_stock_location_id' => $to->id, 'quantity' => '2']],
        ])->assertNotFound();
        $this->assertSame('10.000000', $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /** Un reparto sin carga conserva la UP y admite combustible sólo en su uso propio. */
    public function test_pin_delivery_without_load_keeps_unit_without_creating_stock_movements(): void
    {
        // Prepara el vehículo y habilita las operaciones de reparto del rol.
        [$user, $device, $secret] = $this->fixture();
        $vehicle = Vehicle::factory()->create();
        $customer = Customer::factory()->withAccount()->create();
        Role::findByName('employee', 'web')->givePermissionTo(['can_start_deliveries', 'can_update_own_deliveries', 'can_record_delivery_fuel']);
        $this->login($user, $secret);
        // Inicia sin carga: guarda contexto sin inventar un movimiento de stock.
        $start = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/repartos', [
            'start_without_load' => true, 'vehicle_id' => $vehicle->public_id, 'pin' => '0007',
        ])->assertCreated()->assertJsonPath('data.production_unit.id', $user->production_unit_id)->assertJsonPath('data.loaded_quantity', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        // El cobro pertenece al reparto; su respuesta no revela saldos empresariales.
        $paymentKey = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $paymentKey)->postJson('/api/v1/repartos/'.$start->json('data.id').'/cobros', [
            'client_reference' => $customer->public_id, 'client_event_id' => $paymentKey, 'amount' => '100.000', 'payment_method' => 'cash',
        ])->assertOk()->assertJsonMissingPath('data.0.balance_before')->assertJsonMissingPath('data.0.balance_after');
        // Registra combustible vinculado a ese uso y cierra el reparto confirmando con el PIN.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/combustible', [
            'usage_id' => $start->json('data.vehicle_usage.id'), 'liters' => '5.000', 'amount' => '300.000', 'fuel_type' => 'diesel',
        ])->assertCreated();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/repartos/'.$start->json('data.id').'/cierre', [
            'pin' => '0007', 'returned_quantity' => 0,
        ])->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.financial_summary.customers.0.balance_before')
            ->assertJsonMissingPath('data.closed_financial_summary.customers.0.balance_after');
    }

    /** Los reportes SQL filtran antes de calcular métricas y opciones. */
    public function test_sql_report_aggregates_and_options_do_not_include_another_unit(): void
    {
        // Prepara una recolección propia y otra ajena con cantidades distinguibles.
        [$user, $device, $secret] = $this->fixture();
        $ownFlock = Flock::factory()->create(['production_unit_id' => $user->production_unit_id]);
        EggCollection::factory()->create(['flock_id' => $ownFlock->id, 'quantity' => 7]);
        EggCollection::factory()->create(['quantity' => 100]);
        // Consulta el motor real con el contexto de la cuenta restringida.
        $source = app(EggProductionReportSource::class);
        $query = app(ReportQueryNormalizer::class)->normalize('lots.egg-production', ['metrics' => ['egg_count']]);
        $access = app(ProductionUnitAccess::class);
        $result = $access->within($user->production_unit_id, fn () => $source->preview($query));
        $this->assertSame('7', $result->rows[0]['egg_count']);
        $options = $access->within($user->production_unit_id, fn () => $source->options('production_unit_id', '', 1));
        $this->assertCount(1, $options['data']);
        $this->assertSame($user->production_unit_id, $options['data'][0]->value);
    }

    /** El historial SQL de un lote no expone instantáneas de movimientos que cruzaron otra UP. */
    public function test_flock_history_hides_cross_unit_movement_snapshots(): void
    {
        // Prepara una admisión propia y una redistribución histórica hacia otra UP.
        [$user, $device, $secret] = $this->fixture();
        $house = PoultryHouse::factory()->create(['production_unit_id' => $user->production_unit_id]);
        $flock = Flock::factory()->create(['poultry_house_id' => $house->id, 'production_unit_id' => $user->production_unit_id]);
        $other = Flock::factory()->create();
        $own = FlockMovement::factory()->create(['destination_flock_id' => $flock->id, 'destination_poultry_house_id' => $house->id]);
        FlockMovement::factory()->create(['type' => 'partial_new', 'source_flock_id' => $flock->id,
            'destination_flock_id' => $other->id, 'source_poultry_house_id' => $house->id, 'destination_poultry_house_id' => $other->poultry_house_id,
            'after' => ['other_unit_private_snapshot' => ['production_unit_id' => $other->production_unit_id]]]);
        // Consulta el historial completo con el contexto de la UP de registro.
        $history = app(ProductionUnitAccess::class)->within($user->production_unit_id,
            fn () => app(ListFlockManagementHistoryQuery::class)->execute($flock, []));
        $this->assertSame(1, $history->total());
        $this->assertSame($own->public_id, $history->items()[0]->id);
    }

    /** Convertir a SQL el reporte de stock no puede descartar el scope de Eloquent. */
    public function test_inventory_report_preserves_unit_scope_in_sql_and_aggregates(): void
    {
        // Prepara saldos del mismo producto en dos UP.
        [$user, $device, $secret] = $this->fixture();
        $product = Product::factory()->create();
        $own = StockLocation::factory()->create(['production_unit_id' => $user->production_unit_id]);
        $other = StockLocation::factory()->create(['production_unit_id' => ProductionUnit::factory()->create()->id]);
        StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $own->id, 'on_hand_quantity' => '10.000000']);
        StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $other->id, 'on_hand_quantity' => '100.000000']);
        // El reporte agregado sólo incluye la UP de contexto.
        $query = app(ReportQueryNormalizer::class)->normalize('inventory.stock-balances', ['groupings' => ['production_unit'], 'metrics' => ['available_stock']]);
        $result = app(ProductionUnitAccess::class)->within($user->production_unit_id,
            fn () => app(InventoryStockBalancesReportSource::class)->preview($query));
        $this->assertCount(1, $result->rows);
        $this->assertSame('10.000000', $result->rows[0]['available_stock']);
    }

    /** Desactivar la UP invalida sesiones y códigos; reactivarla no los revive. */
    public function test_unit_deactivation_revokes_sessions_and_pairing_codes(): void
    {
        // Genera un código sin consumir y una sesión de empleado en la misma UP.
        [$user, $device, $secret, $admin] = $this->fixture();
        $unit = $user->productionUnit;
        $code = app(GeneratePairingCodeAction::class)->execute($admin, 'Pendiente', $unit->id)['code'];
        $this->login($user, $secret);
        $access = app(ProductionUnitAccess::class);
        // Ejecuta las transiciones administrativas y comprueba que no resucitan autorizaciones.
        $access->within(null, fn () => app(ChangeProductionUnitStatusAction::class)->execute($unit, ProductionUnitStatus::Inactive, $admin));
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
        $access->within(null, fn () => app(ChangeProductionUnitStatusAction::class)->execute($unit, ProductionUnitStatus::Active, $admin));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/shared-devices/pairing', ['code' => $code])->assertUnprocessable();
    }

    /** Prepara una cuenta PIN, su dispositivo y un administrador. @return array{User, SharedDevice, string, User} */
    private function fixture(): array
    {
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles('admin');
        $unit = ProductionUnit::factory()->create();
        $user = User::factory()->devicePin($unit)->create();
        $user->syncRoles('employee');
        Role::findByName('employee', 'web')->syncPermissions(['can_login_shared', 'can_view_production_units', 'can_view_poultry_houses',
            'can_view_inventory', 'can_move_inventory', 'can_manage_products', 'can_manage_users']);
        $device = SharedDevice::query()->create(['name' => 'Tablet de prueba', 'production_unit_id' => $unit->id,
            'credential_hash' => hash('sha256', 'device-test-secret'), 'credential_expires_at' => now()->addYear(),
            'enrolled_by' => $admin->id, 'enrolled_at' => now()]);

        return [$user, $device, $device->id.'|device-test-secret', $admin];
    }

    /** Inicia el flujo nativo y conserva sus tres credenciales de contexto. */
    private function login(User $user, string $secret): void
    {
        $result = $this->withHeader('X-Shared-Device-Token', $secret)->postJson('/api/v1/shared-device/login-pin', ['user_id' => $user->id, 'pin' => '0007'])
            ->assertOk()->assertJsonPath('session.up_id', $user->production_unit_id);
        $this->withToken($result->json('access_token'))->withHeader('X-GAM-Session', $result->json('session.id'));
    }
}
