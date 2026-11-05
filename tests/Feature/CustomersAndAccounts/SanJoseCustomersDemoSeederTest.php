<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Database\Seeders\CustomersAndAccounts\SanJoseCustomersDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class SanJoseCustomersDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Las fichas tienen puntos distintos y tres cuentas por estado, sin duplicarse al repetir la carga. */
    public function test_seeds_nine_located_customers_with_three_of_each_balance_status(): void
    {
        // Preparación: registra un administrador y la localidad del catálogo.
        $this->createAdmin();
        $department = Department::factory()->create(['name' => 'San José']);
        $locality = Locality::factory()->for($department)->create(['name' => 'San José de Mayo']);

        // Carga: ejecuta dos veces para comprobar las claves de las operaciones.
        $this->app->call([new SanJoseCustomersDemoSeeder, 'run']);
        $this->app->call([new SanJoseCustomersDemoSeeder, 'run']);

        // Consulta: comprueba saldos, historial auditado y nueve puntos distintos en el casco urbano.
        $customers = Customer::query()->with('account')->get();
        $this->assertCount(9, $customers);
        $this->assertSame(
            ['-640.250', '-320.125', '-95.000', '0.000', '0.000', '0.000', '780.500', '1250.000', '2450.750'],
            $customers->pluck('account.balance')->sort(SORT_NUMERIC)->values()->all(),
        );
        $this->assertDatabaseCount('customer_account_movements', 8);
        $this->assertDatabaseCount('customer_operations', 17);
        $this->assertDatabaseCount('activity_log', 17);
        $this->assertDatabaseHas('customer_accounts', ['balance' => '0.000', 'version' => 2]);
        $this->assertSame([$locality->id], $customers->pluck('locality_id')->unique()->values()->all());
        $this->assertCount(9, $customers->map(fn (Customer $customer): string => $customer->latitude.','.$customer->longitude)->unique());
        foreach ($customers as $customer) {
            $this->assertGreaterThanOrEqual(-34.342, (float) $customer->latitude);
            $this->assertLessThanOrEqual(-34.336, (float) $customer->latitude);
            $this->assertGreaterThanOrEqual(-56.717, (float) $customer->longitude);
            $this->assertLessThanOrEqual(-56.710, (float) $customer->longitude);
        }
    }

    /** La carga de San José de Mayo se omite en producción aun con el reset demo habilitado. */
    public function test_does_not_seed_in_production(): void
    {
        // Preparación: habilita el reset demo y deja un administrador disponible.
        $this->createAdmin();
        config(['app.demo_db_reset_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        // Carga: invoca el seeder directamente para probar su guarda de entorno.
        try {
            $this->app->call([new SanJoseCustomersDemoSeeder, 'run']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    private function createAdmin(): void
    {
        User::factory()->create()->syncRoles([Role::findOrCreate('admin', 'web')]);
    }
}
