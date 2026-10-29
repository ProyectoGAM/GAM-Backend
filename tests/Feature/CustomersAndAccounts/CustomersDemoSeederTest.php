<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Models\User;
use Database\Seeders\CustomersAndAccounts\CustomersDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CustomersDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Los datos locales son fichas y movimientos reales que se pueden sembrar otra vez sin duplicarlos. */
    public function test_seeder_creates_real_customers_and_is_idempotent(): void
    {
        // Administrador dueño de las operaciones de demostración.
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
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

    /** La guarda propia no hereda el permiso de reset de una instalación de demo en producción. */
    public function test_production_does_not_seed_customers_even_with_demo_reset_enabled(): void
    {
        // La guarda se invoca sin comandos interactivos ni cambios de datos de desarrollo.
        config(['app.demo_db_reset_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->app->call([new CustomersDemoSeeder, 'run']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }
}
