<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: autoriza al administrador y verifica acceso al endpoint versionado.
    public function test_admin_with_the_permission_can_access_the_versioned_admin_endpoint(): void
    {
        // Preparación: crea un administrador con el permiso requerido.
        $admin = $this->userWithAdminPermission();
        Sanctum::actingAs($admin, ['*']);

        // Acción: consulta el endpoint administrativo versionado.
        $this->getJson('/api/v1/administracion')
            ->assertOk()
            ->assertJsonPath('message', 'Bienvenido al área de administración.')
            ->assertJsonPath('user.email', $admin->email);
    }

    // Flujo: autoriza al administrador y verifica acceso al endpoint sin versión.
    public function test_admin_with_the_permission_can_access_the_unversioned_test_endpoint(): void
    {
        // Preparación: crea un administrador con el permiso requerido.
        $admin = $this->userWithAdminPermission();
        Sanctum::actingAs($admin, ['*']);

        // Acción: consulta el endpoint administrativo sin versión.
        $this->getJson('/administracion')
            ->assertOk()
            ->assertJsonPath('user.email', $admin->email);
    }

    // Flujo: autentica a un usuario sin permiso y verifica la respuesta prohibida.
    public function test_authenticated_user_without_the_permission_receives_403(): void
    {
        // Preparación: autentica un usuario sin el permiso administrativo.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Acción: intenta acceder al área administrativa.
        $this->getJson('/administracion')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes autorización para realizar esta acción.');
    }

    // Flujo: solicita administración sin autenticación y verifica la respuesta 401.
    public function test_admin_endpoint_returns_401_without_authentication(): void
    {
        // Acción: consulta el endpoint sin credenciales.
        $this->getJson('/administracion')->assertUnauthorized();
    }

    // Flujo: asigna un permiso mediante un rol y verifica su herencia en la autorización.
    public function test_role_inherits_the_admin_permission(): void
    {
        // Preparación: crea usuario, rol y permiso, y los relaciona.
        $user = User::factory()->create();
        $role = Role::create(['name' => 'operations-manager', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'admin.dashboard.view', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        // Acción: accede al endpoint usando el permiso heredado.
        $this->getJson('/api/v1/administracion')->assertOk();
    }

    // Flujo: elimina lógicamente al usuario y verifica que su token existente deja de funcionar.
    public function test_soft_deleted_user_cannot_use_an_existing_token(): void
    {
        // Preparación: crea el administrador y su token.
        $user = $this->userWithAdminPermission();
        $token = $user->createToken('deactivation-test', ['api:access']);

        // Acción 1: elimina lógicamente al usuario.
        $user->delete();

        // Acción 2: intenta acceder usando el token previo.
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/administracion')
            ->assertUnauthorized();
    }

    private function userWithAdminPermission(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'admin.dashboard.view', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }
}
