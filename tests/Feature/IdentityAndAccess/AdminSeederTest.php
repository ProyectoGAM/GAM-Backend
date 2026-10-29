<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: ambos seeders reparan el rol completo, preservan permisos adicionales y benefician a todos los administradores.
    #[DataProvider('permissionSeeders')]
    public function test_admin_receives_every_registered_permission_after_either_seeder_is_repeated(string $seeder): void
    {
        // Preparación: crea el rol, otro administrador y un permiso agregado después de la carga inicial.
        $this->seed([IdentityPermissionSeeder::class, AdminUserSeeder::class]);
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        Permission::findOrCreate('future-module.manage', 'web');
        $role = Role::findByName('admin', 'web');
        $role->syncPermissions(['identity.web.login']);
        $this->assertFalse($otherAdmin->can('management-plans.manage'));

        // Mutación: repite cada entrada que antes recortaba los permisos del rol.
        $this->seed($seeder);
        $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $expected = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
        $this->assertSame($expected, $admin->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($expected, $otherAdmin->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        foreach ($expected as $permission) {
            $this->assertTrue($admin->can($permission), $permission);
        }
        $this->assertTrue($admin->can('management-plans.manage'));
        $this->assertTrue($admin->can('feed-recipes.manage'));
        $this->assertTrue($admin->can('delivery.start'));

        // Consulta: los roles operativos conservan su alcance acotado.
        $this->assertSame(['identity.shared.login'], Role::findByName('employee', 'web')->permissions->pluck('name')->all());
        $this->assertFalse(Role::findByName('delivery', 'web')->hasPermissionTo('management-plans.manage'));
    }

    /** @return array<string, array{class-string}> */
    public static function permissionSeeders(): array
    {
        return [
            'identity permissions' => [IdentityPermissionSeeder::class],
            'admin account' => [AdminUserSeeder::class],
        ];
    }

    // Flujo: el cliente recibe todos los permisos al iniciar sesión y refrescar el perfil, y puede consultar planes.
    public function test_admin_login_and_profile_expose_all_permissions_and_allow_management_plans(): void
    {
        // Preparación: siembra únicamente identidad y vuelve a ejecutar el seeder que recortaba planes.
        $this->seed([AdminUserSeeder::class, IdentityPermissionSeeder::class]);
        $expected = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();

        // Request: inicia una sesión personal real y verifica la lista consumida por el frontend.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => config('auth.admin.email'),
            'password' => config('auth.admin.password'),
            'device_name' => 'admin-permissions-test',
        ])->assertOk();
        $this->assertSame($expected, collect($login->json('user.permissions'))->sort()->values()->all());

        // Request: consulta el perfil y las plantillas con el token emitido por el login.
        $profile = $this->withToken($login->json('access_token'))->getJson('/api/v1/me')->assertOk();
        $this->assertSame($expected, collect($profile->json('data.permissions'))->sort()->values()->all());
        $this->getJson('/api/v1/plantillas-manejo')->assertOk();
    }

    // Flujo: la sesión web entrega al frontend el catálogo completo y permite abrir planes de manejo.
    public function test_web_admin_receives_every_permission_and_can_open_management_plans(): void
    {
        // Preparación: crea el administrador y sincroniza la lista completa del rol.
        $this->seed([IdentityPermissionSeeder::class, AdminUserSeeder::class]);
        $expected = Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();

        // Request: inicia sesión mediante cookies y comprueba los permisos del login.
        $login = $this->withHeader('Origin', 'http://localhost:4200')->postJson('/api/v1/auth/web/login', [
            'email' => config('auth.admin.email'),
            'password' => config('auth.admin.password'),
        ])->assertOk()->assertJsonMissingPath('access_token');
        $this->assertSame($expected, collect($login->json('user.permissions'))->sort()->values()->all());

        // Request: refresca el perfil con la misma sesión y abre el listado protegido.
        $profile = $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame($expected, collect($profile->json('data.permissions'))->sort()->values()->all());
        $this->getJson('/api/v1/plantillas-manejo')->assertOk();
    }

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
        $this->assertDatabaseHas('flocks', ['code' => 'LOT-EGG-TEST', 'current_quantity' => 229]);
        $this->assertDatabaseHas('flocks', ['code' => 'LOT-PESAJE-TEST', 'current_quantity' => 120]);
        $this->assertDatabaseHas('flocks', ['code' => 'LOT-FEED-TEST', 'current_quantity' => 120]);
    }
}
