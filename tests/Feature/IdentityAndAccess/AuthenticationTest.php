<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: consulta el perfil sin token y verifica la respuesta 401 en español.
    public function test_returns_401_when_no_token_is_provided_for_the_profile(): void
    {
        // Acción: solicita el perfil sin autenticación.
        $this->getJson('/api/v1/mi-perfil')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No estás autenticado.');
    }

    // Flujo: usa un método no permitido y verifica el mensaje HTTP localizado.
    public function test_api_method_not_allowed_message_is_in_spanish(): void
    {
        // Acción: envía GET a un endpoint que requiere otro método.
        $this->getJson('/api/v1/autenticacion/inicio-sesion')
            ->assertMethodNotAllowed()
            ->assertJsonPath('message', 'El método HTTP no está permitido.');
    }

    // Flujo: registra un usuario y verifica estado activo, token y persistencia.
    public function test_register_creates_an_active_user_and_returns_a_sanctum_token(): void
    {
        // Acción: registra al usuario con credenciales y dispositivo.
        $response = $this->postJson('/api/v1/autenticacion/registro', [
            'nombre' => 'New User',
            'correo_electronico' => 'new.user@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'test-device',
        ]);

        // Verificación: confirma respuesta, identidad, token y registros creados.
        $response
            ->assertCreated()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_at',
                'abilities',
                'user' => ['id', 'nombre', 'correo_electronico', 'deleted_at', 'roles', 'permissions'],
            ])
            ->assertJsonPath('user.correo_electronico', 'new.user@example.test')
            ->assertJsonPath('user.roles', [])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissingPath('data');

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertDatabaseHas('users', [
            'email' => 'new.user@example.test',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'test-device',
        ]);
    }

    // Flujo: crea credenciales válidas, inicia sesión y verifica el token emitido.
    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        // Preparación: crea un usuario con contraseña conocida.
        $user = User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'correct-password',
        ]);

        // Acción: inicia sesión con las credenciales válidas.
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
        ])->postJson('/api/v1/autenticacion/inicio-sesion', [
            'correo_electronico' => 'login@example.test',
            'password' => 'correct-password',
            'device_name' => 'browser',
        ]);

        // Verificación: confirma identidad de respuesta y token persistido.
        $response
            ->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_at',
                'abilities',
                'user' => ['id', 'nombre', 'correo_electronico', 'deleted_at', 'roles', 'permissions'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissingPath('data');

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'browser',
        ]);
    }

    // Flujo: envia un payload vacio y verifica la validacion del inicio de sesion.
    public function test_login_rejects_an_invalid_payload(): void
    {
        // Accion: intenta iniciar sesion sin credenciales.
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
        ])->postJson('/api/v1/autenticacion/inicio-sesion', []);

        // Verificacion: confirma que la request no llega a autenticacion ni devuelve 419.
        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['correo_electronico', 'password']);
    }

    // Flujo: intenta iniciar sesión con contraseña incorrecta y verifica que no se emite token.
    public function test_login_returns_401_for_invalid_credentials(): void
    {
        // Preparación: crea un usuario con una contraseña diferente.
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'correct-password',
        ]);

        // Acción: intenta iniciar sesión con credenciales inválidas.
        $response = $this->postJson('/api/v1/autenticacion/inicio-sesion', [
            'correo_electronico' => 'login@example.test',
            'password' => 'wrong-password',
        ]);

        // Verificación: confirma rechazo y ausencia de tokens.
        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Las credenciales proporcionadas no son correctas.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // Flujo: elimina lógicamente un usuario e intenta autenticarlo sin éxito.
    public function test_soft_deleted_usuarios_cannot_login(): void
    {
        // Preparación: crea y desactiva lógicamente al usuario.
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
        ]);
        $user->delete();

        // Acción: intenta iniciar sesión con el usuario eliminado.
        $this->postJson('/api/v1/autenticacion/inicio-sesion', [
            'correo_electronico' => 'inactive@example.test',
            'password' => 'correct-password',
        ])->assertUnauthorized();

        // Verificación: confirma que no se crea ningún token.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // Flujo: elimina un usuario con el reloj congelado y verifica la marca de tiempo.
    public function test_soft_delete_records_when_the_user_was_disabled(): void
    {
        // Preparación: crea el usuario y congela el tiempo de la prueba.
        $user = User::factory()->create();
        $this->freezeTime();

        // Acción: elimina lógicamente al usuario y recupera su registro histórico.
        $user->delete();
        $storedUser = User::withTrashed()->findOrFail($user->id);

        // Verificación: confirma estado eliminado y timestamp esperado.
        $this->assertTrue($storedUser->trashed());
        $this->assertNotNull($storedUser->deleted_at);
        $this->assertSame(now()->toDateTimeString(), $storedUser->deleted_at->toDateTimeString());
    }

    // Flujo: envía un registro vacío y verifica todos los campos obligatorios.
    public function test_register_rejects_an_invalid_payload(): void
    {
        // Acción: intenta registrar un usuario sin datos.
        $response = $this->postJson('/api/v1/autenticacion/registro', []);

        // Verificación: confirma validación y mensaje de correo requerido.
        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'correo_electronico', 'password'])
            ->assertJsonPath('errors.correo_electronico.0', 'El campo correo electrónico es obligatorio.');
    }

    // Flujo: registra un correo existente y verifica el error de unicidad en español.
    public function test_register_reports_duplicate_email_in_spanish(): void
    {
        // Preparación: crea el usuario que ya posee el correo.
        User::factory()->create(['email' => 'existing@example.test']);

        // Acción: intenta registrar otro usuario con el mismo correo.
        $this->postJson('/api/v1/autenticacion/registro', [
            'nombre' => 'Another User',
            'correo_electronico' => 'existing@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.correo_electronico.0', 'El valor de correo electrónico ya está en uso.');
    }

    // Flujo: crea un usuario autenticado y verifica que puede leer su perfil completo.
    public function test_authenticated_user_can_read_their_profile(): void
    {
        // Preparación: crea el usuario y su token de acceso.
        $user = User::factory()->create([
            'email' => 'profile@example.test',
        ]);
        $token = $user->createToken('profile-test', ['api:access']);

        // Acción: consulta el perfil usando el token.
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mi-perfil')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.correo_electronico', 'profile@example.test')
            ->assertJsonPath('data.deleted_at', null)
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);
    }

    // Flujo: cierra la sesión y verifica que el token deja de ser utilizable.
    public function test_logout_revokes_the_current_token(): void
    {
        // Preparación: crea el usuario y el token que será revocado.
        $user = User::factory()->create();
        $token = $user->createToken('logout-test', ['api:access']);

        // Acción 1: cierra la sesión con el token actual.
        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/autenticacion/cerrar-sesion')
            ->assertOk()
            ->assertJsonPath('message', 'La sesión se cerró correctamente.');

        // Verificación intermedia: confirma que el token fue eliminado.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
        ]);

        Auth::forgetGuards();

        // Acción 2: intenta reutilizar el token revocado.
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mi-perfil')
            ->assertUnauthorized();
    }

    // Flujo: crea un token expirado y verifica que no permite leer el perfil.
    public function test_expired_token_returns_401(): void
    {
        // Preparación: crea un usuario y un token con fecha vencida.
        $user = User::factory()->create();
        $token = $user->createToken('expired-test', ['api:access'], now()->subMinute());

        // Acción: solicita el perfil utilizando el token expirado.
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/mi-perfil')
            ->assertUnauthorized();

        // Verificación: confirma que el token vencido permanece registrado.
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
        ]);
    }
}
