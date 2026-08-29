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

    public function test_admin_with_the_permission_can_access_the_versioned_admin_endpoint(): void
    {
        $admin = $this->userWithAdminPermission();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/administracion')
            ->assertOk()
            ->assertJsonPath('message', 'Bienvenido al área de administración.')
            ->assertJsonPath('user.email', $admin->email);
    }

    public function test_admin_with_the_permission_can_access_the_unversioned_test_endpoint(): void
    {
        $admin = $this->userWithAdminPermission();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/administracion')
            ->assertOk()
            ->assertJsonPath('user.email', $admin->email);
    }

    public function test_authenticated_user_without_the_permission_receives_403(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/administracion')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes autorización para realizar esta acción.');
    }

    public function test_admin_endpoint_returns_401_without_authentication(): void
    {
        $this->getJson('/administracion')->assertUnauthorized();
    }

    public function test_role_inherits_the_admin_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'operations-manager', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'admin.dashboard.view', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/administracion')->assertOk();
    }

    public function test_soft_deleted_user_cannot_use_an_existing_token(): void
    {
        $user = $this->userWithAdminPermission();
        $token = $user->createToken('deactivation-test', ['api:access']);
        $user->delete();

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
