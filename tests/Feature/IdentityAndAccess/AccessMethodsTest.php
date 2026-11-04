<?php

namespace Tests\Feature\IdentityAndAccess;

use App\Actions\IdentityAndAccess\UpdateUserRoleAction;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AccessMethodsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.pin.pepper' => 'access-test-pepper']);
    }

    /** El alta PIN guarda nulos reales, PIN seguro y una única UP. */
    public function test_admin_registers_pin_account_without_email_or_password(): void
    {
        // Prepara administración, rol y una UP activa.
        $admin = $this->admin();
        $unit = ProductionUnit::factory()->create();
        Sanctum::actingAs($admin);
        // Registra la cuenta con PIN y consulta su persistencia.
        $id = $this->postJson('/api/v1/users', ['name' => 'Operario PIN', 'rol_id' => Role::findByName('employee', 'web')->id,
            'pin' => '0007', 'pin_confirmation' => '0007', 'up_id' => $unit->id])
            ->assertCreated()->assertJsonPath('data.email', null)->assertJsonPath('data.modalidad_acceso', 'device_pin')
            ->assertJsonPath('data.up_id', $unit->id)->json('data.id');
        $user = User::findOrFail($id);
        $this->assertNull($user->password);
        $this->assertTrue(app(PinHasher::class)->check('0007', $user->pin_hash));
        $this->assertCount(1, $user->roles);
    }

    /** El alta por correo conserva la modalidad y no exige PIN ni UP. */
    public function test_admin_registers_email_account_without_pin_or_unit(): void
    {
        // Prepara una sesión administrativa y registra la cuenta personal.
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/users', ['name' => 'Personal', 'email' => 'personal@example.test',
            'password' => 'new-password', 'password_confirmation' => 'new-password', 'rol_id' => Role::findByName('delivery', 'web')->id])
            ->assertCreated()->assertJsonPath('data.modalidad_acceso', 'email_password')->assertJsonPath('data.up_id', null);
        $this->assertTrue(Hash::check('new-password', User::where('email', 'personal@example.test')->firstOrFail()->password));
    }

    /** Las combinaciones incompatibles se rechazan antes de crear usuarios. */
    #[DataProvider('invalidRegistrations')]
    public function test_registration_rejects_incomplete_or_conflicting_credentials(array $fields, array $errors): void
    {
        // Prepara los datos comunes y envía la variante inválida.
        Sanctum::actingAs($this->admin());
        $data = ['name' => 'Inválido', 'rol_id' => Role::findByName('employee', 'web')->id, ...$fields];
        $count = User::count();
        $this->postJson('/api/v1/users', $data)->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertDatabaseCount('users', $count);
    }

    public static function invalidRegistrations(): array
    {
        return [
            'sin credenciales' => [[], ['pin', 'up_id']],
            'correo sin contraseña' => [['email' => 'missing@example.test'], ['password']],
            'PIN numérico' => [['pin' => 1234, 'pin_confirmation' => 1234], ['pin']],
            'PIN con espacios' => [['pin' => '0007 ', 'pin_confirmation' => '0007 '], ['pin']],
            'sin rol' => [['rol_id' => null], ['rol_id']],
            'contraseña sin correo' => [['password' => 'new-password', 'password_confirmation' => 'new-password'], ['password']],
        ];
    }

    /** Sin rol no obtiene sesión aunque conserve una contraseña correcta. */
    public function test_unassigned_cannot_login_and_assignment_revokes_existing_sessions(): void
    {
        // Crea una cuenta habilitada y una sesión nativa real.
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->syncRoles('delivery');
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        // Asigna Sin rol y comprueba que desaparece toda autorización de acceso.
        app(UpdateUserRoleAction::class)->execute($admin, $user, Role::findByName('unassigned', 'web')->id);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->withToken($login->json('access_token'))->getJson('/api/v1/me')->assertUnauthorized();
    }

    /** Los cambios propios de contraseña y PIN operativo revocan todas las sesiones. */
    #[DataProvider('personalCredentialChanges')]
    public function test_personal_credential_change_revokes_current_and_other_sessions(string $path, string $method, array $payload): void
    {
        // Crea dos sesiones personales del mismo administrador.
        $user = $this->admin();
        $service = app(AuthSessionService::class);
        $service->createWebPersonal($user);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        // Cambia la credencial mediante su sesión actual.
        $response = $this->withToken($login->json('access_token'))->{$method}($path, $payload);
        $response->assertOk()->assertJsonPath('requiere_reingreso', true);
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
        $this->assertSame(0, $user->tokens()->count());
        $fresh = $user->fresh();
        if ($method === 'putJson') {
            $this->assertTrue(app(PinHasher::class)->check('0007', $fresh->operation_pin_hash));
            $this->assertNull($fresh->pin_hash);
            $this->assertFalse($fresh->pin_enabled);
        } else {
            $this->assertTrue(Hash::check('new-password', $fresh->password));
        }
    }

    public static function personalCredentialChanges(): array
    {
        return [
            'contraseña' => ['/api/v1/auth/contrasena', 'patchJson', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password']],
            'PIN operativo Admin' => ['/api/v1/auth/pin-operativo', 'putJson', ['current_password' => 'password', 'pin' => '0007', 'pin_confirmation' => '0007']],
        ];
    }

    /** Un cambio fallido no revoca sesiones ni modifica las credenciales. */
    public function test_wrong_current_password_preserves_sessions_and_rejects_identity_edits(): void
    {
        // Inicia una sesión y conserva el hash original para comparar.
        $user = $this->admin();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withToken($login->json('access_token'));
        // Rechaza la credencial actual incorrecta y un cambio de correo no permitido.
        $this->patchJson('/api/v1/auth/contrasena', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnauthorized();
        $this->patchJson('/api/v1/auth/contrasena', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password', 'email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, $user->tokens()->count());
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /** Sin rol es un rol protegido y no admite permisos. */
    public function test_unassigned_role_cannot_be_modified_or_deleted(): void
    {
        // Intenta editar y borrar el rol de sistema desde Admin.
        Sanctum::actingAs($this->admin());
        $role = Role::findByName('unassigned', 'web');
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => ['can_login_web'], 'version' => $role->version])->assertForbidden();
        $this->deleteJson('/api/v1/roles/'.$role->id, ['version' => $role->version])->assertForbidden();
        $this->assertCount(0, $role->permissions);
    }

    /** El fallo de auditoría revierte la credencial y la revocación juntas. */
    public function test_audit_failure_rolls_back_credential_and_session_revocations(): void
    {
        // Prepara una sesión real y simula un fallo en la escritura de auditoría.
        $user = $this->admin();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        Exceptions::fake();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Fallo de auditoría de prueba'));
        // El cambio no puede completarse sin su entrada de auditoría.
        $this->withToken($login->json('access_token'))->patchJson('/api/v1/auth/contrasena', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertInternalServerError();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, $user->authSessions()->whereNull('revoked_at')->count());
    }

    /** Restablecer el PIN operativo de otra cuenta cierra sólo las sesiones de esa cuenta. */
    public function test_admin_pin_reset_preserves_admin_session_and_revokes_subject_sessions(): void
    {
        // Prepara las sesiones del administrador y la cuenta modificada.
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->syncRoles('delivery');
        $service = app(AuthSessionService::class);
        $service->createPersonal($user, $user->createToken('subject', ['api:access'], now()->addDay()));
        $login = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        // Cambia el PIN operativo y verifica el alcance exacto de la revocación.
        $this->withToken($login->json('access_token'))->putJson('/api/v1/users/'.$user->id.'/pin', ['pin' => '0012', 'pin_confirmation' => '0012'])->assertOk();
        $this->assertSame(0, $user->authSessions()->whereNull('revoked_at')->count());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $admin->authSessions()->whereNull('revoked_at')->count());
        $this->assertTrue(app(PinHasher::class)->check('0012', $user->fresh()->operation_pin_hash));
    }

    /** Prepara un administrador con el catálogo real de permisos. */
    private function admin(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles('admin');

        return $admin;
    }
}
