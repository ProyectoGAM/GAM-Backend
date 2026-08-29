<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_no_token_is_provided_for_the_profile(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_register_creates_an_active_user_and_returns_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new.user@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'test-device',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'new.user@example.test')
            ->assertJsonPath('user.roles', [])
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertDatabaseHas('users', [
            'email' => 'new.user@example.test',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'test-device',
        ]);
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.test',
            'password' => 'correct-password',
            'device_name' => 'browser',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'browser',
        ]);
    }

    public function test_login_returns_401_for_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_soft_deleted_users_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ]);
        $user->delete();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_soft_delete_records_when_the_user_was_disabled(): void
    {
        $user = User::factory()->create();
        $this->freezeTime();

        $user->delete();
        $storedUser = User::withTrashed()->findOrFail($user->id);

        $this->assertTrue($storedUser->trashed());
        $this->assertNotNull($storedUser->deleted_at);
        $this->assertSame(now()->toDateTimeString(), $storedUser->deleted_at->toDateTimeString());
    }

    public function test_register_rejects_an_invalid_payload(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password'])
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }

    public function test_authenticated_user_can_read_their_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'profile@example.test',
        ]);
        $token = $user->createToken('profile-test', ['api:access']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'profile@example.test')
            ->assertJsonPath('data.deleted_at', null)
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('logout-test', ['api:access']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);

        Auth::forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired-test', ['api:access'], now()->subMinute());

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }
}
