<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\IdentityAndAccess\AuthSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SessionManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_list_and_revoke_a_personal_session(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $user->givePermissionTo(Permission::findOrCreate('identity.personal.login', 'web'));
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'session-test',
        ])->assertOk();

        $token = $response->json('access_token');
        $sessionId = $response->json('session.id');

        $this->withToken($token)
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sessionId);

        $this->withToken($token)
            ->deleteJson('/api/v1/sessions/'.$sessionId)
            ->assertOk();

        $this->assertDatabaseHas('auth_sessions', [
            'id' => $sessionId,
            'revoked_reason' => 'self_revoked',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_password_reset_revokes_existing_sessions(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('identity.users.manage', 'web'));
        $user = User::factory()->create(['password' => 'old-password']);
        $user->givePermissionTo(Permission::findOrCreate('identity.personal.login', 'web'));
        $token = $user->createToken('old-session', ['api:access']);
        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'kind' => 'personal',
            'transport' => 'bearer',
            'auth_method' => 'password',
            'personal_access_token_id' => $token->accessToken->getKey(),
            'issued_at' => now(),
            'expires_at' => now()->addDays(90),
            'last_user_activity_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['api:access']);
        $this->putJson('/api/v1/users/'.$user->getKey().'/password', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'new-password',
        ])->assertOk();
    }

    public function test_admin_can_revoke_all_sessions_without_changing_the_password(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('identity.sessions.manage', 'web'));
        $user = User::factory()->create(['password' => 'unchanged-password']);
        $token = $user->createToken('revoked-session', ['api:access']);
        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'kind' => 'personal',
            'transport' => 'bearer',
            'auth_method' => 'password',
            'personal_access_token_id' => $token->accessToken->getKey(),
            'issued_at' => now(),
            'expires_at' => now()->addDays(90),
            'last_user_activity_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['api:access']);
        $this->deleteJson('/api/v1/users/'.$user->getKey().'/sessions')->assertOk();

        $this->assertSame('admin_revoked', $session->fresh()->revoked_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
        $this->assertTrue(password_verify('unchanged-password', $user->fresh()->password));
    }
}
