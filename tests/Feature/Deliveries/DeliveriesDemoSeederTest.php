<?php

namespace Tests\Feature\Deliveries;

use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Database\Seeders\Deliveries\DeliveriesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DeliveriesDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    // Carga la misma demo que el reset de la VPS y verifica los tres repartos del historial.
    public function test_demo_reset_seeds_completed_deliveries_with_geolocated_stops(): void
    {
        // Prepara el entorno de despliegue sobre la base de pruebas.
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.demo_db_reset_enabled' => true,
            'auth.demo_delivery.password' => null,
            'auth.demo_delivery.pin' => null,
        ]);
        Storage::fake('local');

        // Ejecuta la cadena de seeding con la confirmación de producción habilitada.
        $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        // Consulta el reparto y comprueba el cierre, las visitas y el balance.
        $deliveries = Delivery::query()->with(['stops', 'vehicle', 'vehicleUsage'])->get()->keyBy('vehicle_reference');
        $delivery = $deliveries->get('DEMO-2040');
        $this->assertSame(DeliveryStatus::Completed, $delivery->status);
        $this->assertSame('DEMO-2040', $delivery->vehicle->plate);
        $this->assertSame(120, $delivery->loaded_quantity);
        $this->assertSame(60, $delivery->returned_quantity);
        $this->assertSame($delivery->production_unit_id, $delivery->return_production_unit_id);
        $this->assertSame(3, $delivery->stops->count());
        $this->assertSame(60, (int) $delivery->stops->where('status', DeliveryStopStatus::Delivered)->sum('delivered_quantity'));
        $this->assertSame(1, $delivery->stops->where('status', DeliveryStopStatus::NotDelivered)->count());
        $this->assertSame('600.000', $delivery->financial_snapshot['merchandise_amount']);
        $this->assertSame('300.000', $delivery->financial_snapshot['collected_amount']);
        $this->assertSame('300.000', $delivery->financial_snapshot['account_delta']);
        $this->assertNotNull($delivery->vehicleUsage->ended_at);
        $this->assertSame(3, $delivery->accountMovements()->count());

        // Consulta el reparto de Libertad y verifica la distribución de sus clientes para el mapa.
        $longRoute = $deliveries->get('DEMO-2042');
        $this->assertSame(DeliveryStatus::Completed, $longRoute->status);
        $this->assertCount(12, $longRoute->stops);
        $this->assertCount(12, $longRoute->stops->pluck('customer_id')->unique());
        $locatedStops = $longRoute->stops->whereNotNull('latitude')->whereNotNull('longitude');
        $this->assertCount(12, $locatedStops->unique(fn (DeliveryStop $stop): string => $stop->latitude.','.$stop->longitude));
        $this->assertCount(10, $longRoute->stops->where('status', DeliveryStopStatus::Delivered));
        $this->assertSame(0, $longRoute->returned_quantity);

        // Consulta el reparto de Las Piedras y comprueba las paradas y el sobrante.
        $shortRoute = $deliveries->get('DEMO-2041');
        $this->assertSame(DeliveryStatus::Completed, $shortRoute->status);
        $this->assertCount(6, $shortRoute->stops);
        $this->assertSame(30, $shortRoute->returned_quantity);

        // Verifica las dependencias de la demo y las credenciales del repartidor.
        $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $this->assertTrue($admin->can('can_view_admin_dashboard'));
        $this->assertTrue(Role::query()->where('name', 'demo_poultry_house_operator')->exists());
        $this->assertDatabaseHas('users', ['name' => 'Ana Duarte (demo)', 'pin_enabled' => true]);
        $this->assertDatabaseCount('customers', 27);
        $this->assertDatabaseHas('app_notifications', ['source_key' => 'local-inbox-welcome', 'recipient_id' => $admin->id]);
        $driver = $delivery->driver;
        $this->assertTrue($driver->hasRole('delivery'));
        $this->assertFalse(Hash::check('ChangeMe123!', $driver->password));
        $this->assertNotNull($driver->operation_pin_hash);

        // Repite el seeder y comprueba que las operaciones no se duplican.
        $this->app->call([new DeliveriesDemoSeeder, 'run']);
        $this->assertDatabaseCount('deliveries', 3);
        $this->assertDatabaseCount('delivery_stops', 21);
        $this->assertDatabaseCount('customers', 27);
        $this->assertSame(3, $delivery->accountMovements()->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'deliveries', 'event' => 'delivery_closed', 'subject_id' => $delivery->id]);
    }

    // Comprueba que la carga normal en producción mantiene fuera los datos de demo.
    public function test_production_without_demo_reset_loads_only_base_data(): void
    {
        // Deshabilita explícitamente el reset en el entorno simulado.
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.demo_db_reset_enabled' => false]);

        // Ejecuta el seeder general y prueba también la entrada directa del seeder nuevo.
        $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->app->call([new DeliveriesDemoSeeder, 'run']);

        // Verifica el administrador base y la ausencia de datos ficticios.
        $this->assertDatabaseHas('users', ['email' => config('auth.admin.email')]);
        $this->assertDatabaseMissing('users', ['email' => config('auth.demo_delivery.email')]);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('customers', 0);
    }
}
