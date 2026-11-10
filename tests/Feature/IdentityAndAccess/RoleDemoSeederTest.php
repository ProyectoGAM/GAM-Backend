<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Actions\IdentityAndAccess\UpdateRolePermissionsAction;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Database\Seeders\IdentityAndAccess\RoleDemoSeeder;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class RoleDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** El seeder crea cuatro roles auditados con permisos acotados y conserva las ediciones al repetirse. */
    public function test_seeds_editable_roles_with_distinct_permissions_and_preserves_changes(): void
    {
        // Preparación: registra el catálogo y asigna el rol de administrador al actor.
        $admin = $this->createAdmin();
        $initialAdminPermissions = $admin->getAllPermissions()->pluck('name')->sort()->values()->all();

        // Carga y consulta: comprueba los permisos representativos de los cuatro perfiles.
        $this->app->call([new RoleDemoSeeder, 'run']);
        $customers = Role::findByName('demo_customer_management', 'web');
        $collections = Role::findByName('demo_collections', 'web');
        $inventory = Role::findByName('demo_inventory_viewer', 'web');
        $operator = Role::findByName('demo_poultry_house_operator', 'web');

        $this->assertTrue($customers->hasAllPermissions(['can_login_web', 'can_manage_customers']));
        $this->assertFalse($customers->hasPermissionTo('can_view_customer_accounts'));
        $this->assertTrue($collections->hasAllPermissions(['can_view_customer_accounts', 'can_record_customer_payments']));
        $this->assertFalse($collections->hasPermissionTo('can_adjust_customer_accounts'));
        $this->assertTrue($inventory->hasAllPermissions(['can_view_inventory', 'can_view_egg_stock', 'can_view_feed_stock']));
        $this->assertFalse($inventory->hasPermissionTo('can_adjust_inventory'));
        $this->assertTrue($operator->hasAllPermissions(['can_login_shared', 'can_manage_egg_collections', 'can_manage_weighings']));
        $this->assertFalse($operator->hasPermissionTo('can_login_web'));
        $this->assertFalse($customers->isProtected());

        // Mutación: cambia los permisos y repite la carga para comprobar que conserva la edición.
        $this->app->make(UpdateRolePermissionsAction::class)->execute($admin, $customers, ['can_login_web'], $customers->version);
        $this->app->call([new RoleDemoSeeder, 'run']);

        // Consulta: verifica que la segunda carga conserva usuarios, permisos y auditoría.
        $this->assertSame(['can_login_web'], $customers->fresh()->permissions->pluck('name')->all());
        $this->assertSame($initialAdminPermissions, $admin->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('activity_log', 5);
        $this->assertDatabaseHas('activity_log', ['event' => 'role_created', 'source' => 'seeder', 'subject_id' => $customers->id]);
    }

    /** La carga de roles de demostración se omite en producción. */
    public function test_does_not_seed_demo_roles_in_production(): void
    {
        // Preparación: deja un administrador disponible con el reset de demo activo.
        $this->createAdmin();
        config(['app.demo_db_reset_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        // Carga: invoca el seeder directamente para verificar su guarda de entorno.
        try {
            $this->app->call([new RoleDemoSeeder, 'run']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertDatabaseMissing('roles', ['name' => 'demo_customer_management']);
        $this->assertDatabaseMissing('activity_log', ['event' => 'role_created']);
    }

    private function createAdmin(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        return $admin;
    }
}
