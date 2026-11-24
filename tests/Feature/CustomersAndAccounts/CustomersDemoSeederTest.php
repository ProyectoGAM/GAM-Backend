<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Database\Seeders\CustomersAndAccounts\CustomersDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class CustomersDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Los datos locales son fichas y movimientos reales que se pueden sembrar otra vez sin duplicarlos. */
    public function test_seeder_creates_real_customers_and_is_idempotent(): void
    {
        // Administrador dueño de las operaciones de demostración.
        $admin = User::factory()->create();
        $admin->syncRoles([Role::findOrCreate('admin', 'web')]);
        $this->app->call([new CustomersDemoSeeder, 'run']);
        $this->app->call([new CustomersDemoSeeder, 'run']);
        $this->assertDatabaseCount('customers', 6);
        $this->assertDatabaseCount('customer_accounts', 6);
        $this->assertDatabaseCount('customer_account_movements', 2);
        $this->assertDatabaseCount('customer_operations', 8);
        $this->assertDatabaseCount('activity_log', 8);
        $this->assertDatabaseHas('customer_accounts', ['balance' => '1000.000']);
        $this->assertDatabaseHas('customer_accounts', ['balance' => '-150.125']);
    }

    /** La carga se omite en producción cuando el reset de la demo está deshabilitado. */
    public function test_production_does_not_seed_customers_without_demo_reset(): void
    {
        // Prepara un administrador para comprobar la guarda de entorno.
        User::factory()->create()->syncRoles([Role::findOrCreate('admin', 'web')]);
        config(['app.demo_db_reset_enabled' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        // Invoca la carga directamente en el entorno simulado.
        try {
            $this->app->call([new CustomersDemoSeeder, 'run']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        // Consulta las fichas y los movimientos después de la carga.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }
}
