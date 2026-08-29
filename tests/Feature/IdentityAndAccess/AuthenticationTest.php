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
        $this->getJson('/api/v1/mi-perfil')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No estás autenticado.');
    }

    public function test_api_method_not_allowed_message_is_in_spanish(): void
    {
        $this->getJson('/api/v1/autenticacion/inicio-sesion')
            ->assertMethodNotAllowed()
            ->assertJsonPath('message', 'El método HTTP no está permitido.');
    }

    public function test_register_creates_an_active_user_and_returns_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/v1/autenticacion/registro', [
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

        $response = $this->postJson('/api/v1/autenticacion/inicio-sesion', [
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

        $response = $this->postJson('/api/v1/autenticacion/inicio-sesion', [
            'email' => 'login@example.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Las credenciales proporcionadas no son correctas.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_soft_deleted_users_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ]);
        $user->delete();

        $this->postJson('/api/v1/autenticacion/inicio-sesion', [
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
        $response = $this->postJson('/api/v1/autenticacion/registro', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password'])
            ->assertJsonPath('errors.email.0', 'El campo correo electrónico es obligatorio.');
    }

    public function test_register_reports_duplicate_email_in_spanish(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->postJson('/api/v1/autenticacion/registro', [
            'name' => 'Another User',
            'email' => 'existing@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'El valor de correo electrónico ya está en uso.');
    }

    public function test_authenticated_user_can_read_their_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'profile@example.test',
        ]);
        $token = $user->createToken('profile-test', ['api:access']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mi-perfil')
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
            ->postJson('/api/v1/autenticacion/cerrar-sesion')
            ->assertOk()
            ->assertJsonPath('message', 'La sesión se cerró correctamente.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
        ]);

        Auth::forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mi-perfil')
            ->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired-test', ['api:access'], now()->subMinute());

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mi-perfil')
            ->assertUnauthorized();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
        ]);
    }
}
