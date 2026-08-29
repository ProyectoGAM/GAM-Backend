<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_seeder_creates_the_active_admin_with_role_and_permission(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', config('auth.admin.email'))
            ->firstOrFail();

        $this->assertModelExists($admin);
        $this->assertFalse($admin->trashed());
        $this->assertNull($admin->deleted_at);
        $this->assertTrue(Hash::check(config('auth.admin.password'), $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->can('admin.dashboard.view'));
        $this->assertDatabaseHas('permissions', [
            'name' => 'admin.dashboard.view',
            'guard_name' => 'web',
        ]);
    }

    public function test_seeded_admin_can_login_and_access_the_admin_endpoint(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => config('auth.admin.email'),
            'password' => config('auth.admin.password'),
            'device_name' => 'seed-test',
        ]);

        $response->assertOk();

        $this->withToken($response->json('access_token'))
            ->getJson('/admin')
            ->assertOk()
            ->assertJsonPath('user.email', config('auth.admin.email'));
    }

    public function test_seeding_restores_a_soft_deleted_admin(): void
    {
        $this->seed();
        $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $admin->delete();

        $this->seed();
        $restoredAdmin = User::withTrashed()->where('email', config('auth.admin.email'))->firstOrFail();

        $this->assertFalse($restoredAdmin->trashed());
        $this->assertNull($restoredAdmin->deleted_at);
    }
}
