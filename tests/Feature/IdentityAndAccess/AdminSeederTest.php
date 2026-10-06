<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: ejecuta el seeder y verifica administrador, permisos, rol y geografías iniciales.
    public function test_database_seeder_creates_the_active_admin_with_role_and_permission(): void
    {
        // Acción: ejecuta todos los seeders de la aplicación.
        $this->seed();

        // Acción: consulta el administrador sembrado.
        $admin = User::query()
            ->where('email', config('auth.admin.email'))
            ->firstOrFail();

        // Verificación: confirma identidad activa, credenciales, permisos y datos base.
        $this->assertModelExists($admin);
        $this->assertFalse($admin->trashed());
        $this->assertNull($admin->deleted_at);
        $this->assertTrue(Hash::check(config('auth.admin.password'), $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->can('admin.dashboard.view'));
        $this->assertTrue($admin->can('audit.view'));
        $this->assertTrue($admin->can('geography.manage'));
        $this->assertTrue($admin->can('production-units.manage'));
        $this->assertTrue($admin->can('poultry-houses.manage'));
        $this->assertDatabaseHas('permissions', [
            'name' => 'admin.dashboard.view',
            'guard_name' => 'web',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'audit.view',
            'guard_name' => 'web',
        ]);
        $this->assertDatabaseCount('departments', 19);
    }

    // Flujo: siembra el administrador, inicia sesión y accede al endpoint protegido.
    public function test_seeded_admin_can_login_and_access_the_admin_endpoint(): void
    {
        // Acción 1: ejecuta los seeders para crear el administrador.
        $this->seed();

        // Acción 2: inicia sesión con las credenciales sembradas.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => config('auth.admin.email'),
            'password' => config('auth.admin.password'),
            'device_name' => 'seed-test',
        ]);

        $response->assertOk();

        // Acción 3: usa el token para consultar administración.
        $this->withToken($response->json('access_token'))
            ->getJson('/administration')
            ->assertOk()
            ->assertJsonPath('user.email', config('auth.admin.email'));
    }

    // Flujo: elimina lógicamente al administrador, vuelve a sembrar y verifica su restauración.
    public function test_seeding_restores_a_soft_deleted_admin(): void
    {
        // Acción 1: crea el administrador inicial mediante el seeder.
        $this->seed();

        // Acción 2: elimina lógicamente al administrador.
        $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $admin->delete();

        // Acción 3: ejecuta nuevamente el seeder para restaurarlo.
        $this->seed();

        // Acción 4: consulta el administrador incluyendo eliminados.
        $restoredAdmin = User::withTrashed()->where('email', config('auth.admin.email'))->firstOrFail();

        // Verificación: confirma que el administrador quedó activo nuevamente.
        $this->assertFalse($restoredAdmin->trashed());
        $this->assertNull($restoredAdmin->deleted_at);
    }

    // Flujo: producción conserva la base sin datos demo cuando el reset está apagado.
    public function test_production_seed_keeps_demo_data_disabled_without_the_reset_flag(): void
    {
        // Preparación: fuerza producción con el reset apagado en la base aislada de pruebas.
        $this->app->instance('env', 'production');
        config(['app.demo_db_reset_enabled' => false]);

        // Acción: ejecuta el seeder normal y consulta que no haya datos ficticios.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);

        $this->assertDatabaseCount('production_units', 0);
        $this->assertDatabaseCount('flocks', 0);
    }

    // Flujo: el reset explícito siembra datos útiles y conserva el administrador activo.
    public function test_explicit_production_reset_flag_seeds_demo_and_usable_admin(): void
    {
        // Preparación: activa sólo la opción de seed demo dentro de la base aislada de pruebas.
        $this->app->instance('env', 'production');
        config(['app.demo_db_reset_enabled' => true]);

        // Acción: ejecuta la carga completa equivalente al reset explícito de producción.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);

        // Verificación: comprueba el admin activo, sus permisos y registros útiles del demo.
        $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $this->assertFalse($admin->trashed());
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->can('admin.dashboard.view'));
        $this->assertDatabaseHas('production_units', ['name' => 'Granja El Ombú']);
        $this->assertDatabaseHas('flocks', ['code' => 'DEMO-LOT-A']);
    }
}
