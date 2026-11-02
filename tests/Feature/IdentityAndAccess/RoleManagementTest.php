<?php

namespace Tests\Feature\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\IdentityAndAccess\Role;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class RoleManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: las rutas de configuración no aceptan solicitudes sin autenticar.
    public function test_role_endpoints_return_401_without_authentication(): void
    {
        // Requests: intenta consultar, crear y modificar roles sin credenciales.
        $this->getJson('/api/v1/permisos')->assertUnauthorized();
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->postJson('/api/v1/roles', [])->assertUnauthorized();
        $this->putJson('/api/v1/roles/1/permisos', [])->assertUnauthorized();
    }

    /** @return array<string, array{string}> */
    public static function nonAdministratorRoles(): array
    {
        return ['empleado' => ['employee'], 'repartidor' => ['delivery'], 'personalizado' => ['custom_operator']];
    }

    // Flujo: ningún permiso delegable permite administrar roles ni tomar control de Admin.
    #[DataProvider('nonAdministratorRoles')]
    public function test_non_admin_with_all_permissions_cannot_manage_roles_or_admin_accounts(string $name): void
    {
        // Preparación: concede todo el catálogo a un rol sin identidad Admin.
        $admin = $this->admin();
        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions(Permission::all());
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        // Requests: rechaza todas las vías de cambio, incluidas las rutas anteriores.
        $this->getJson('/api/v1/permisos')->assertForbidden();
        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', ['nombre' => 'Intento', 'permisos' => []])->assertForbidden();
        $this->patchJson('/api/v1/roles/'.$role->id, ['nombre' => 'Intento', 'version' => 1])->assertForbidden();
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => [], 'version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/roles/'.$role->id, ['version' => 1])->assertForbidden();
        $this->putJson('/api/v1/usuarios/'.$user->id.'/rol', ['rol_id' => $admin->roles()->firstOrFail()->id])->assertForbidden();
        $this->putJson('/api/v1/users/'.$user->id.'/roles', ['rol_id' => $admin->roles()->firstOrFail()->id])->assertForbidden();
        $this->postJson('/api/v1/users', ['name' => 'Intento', 'email' => 'attempt@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'rol_id' => $role->id])->assertForbidden();
        $this->putJson('/api/v1/users/'.$admin->id.'/password', ['password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$admin->id.'/status', ['enabled' => false])->assertForbidden();
        $this->deleteJson('/api/v1/users/'.$admin->id.'/sessions')->assertForbidden();
        $this->assertFalse($admin->fresh()->trashed());
        $this->assertFalse(Hash::check('changed-password', $admin->fresh()->password));
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    // Flujo: el catálogo agrupa permisos con textos útiles y no ofrece delegar la administración de roles.
    public function test_catalog_is_grouped_and_bounded_role_filters_are_validated(): void
    {
        // Preparación: autentica a Admin y consulta los módulos disponibles.
        $this->admin();
        $catalog = $this->getJson('/api/v1/permisos')->assertOk()->json('data');
        $feed = collect($catalog)->firstWhere('modulo', 'feed_stock');
        $adjustment = collect($feed['permisos'])->firstWhere('nombre', 'can_adjust_feed_stock');
        $this->assertSame('Ajustar stock de alimento', $adjustment['etiqueta']);
        $this->assertSame(['can_view_feed_stock'], $adjustment['requiere']);
        $this->assertNotEmpty($adjustment['descripcion']);
        $this->assertTrue($adjustment['asignable']);

        // Requests: pagina roles y rechaza límites o campos no permitidos.
        $this->getJson('/api/v1/roles?por_pagina=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/roles?por_pagina=101')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        $this->getJson('/api/v1/roles?orden=guard_name')->assertUnprocessable()->assertJsonValidationErrors('orden');
        $this->getJson('/api/v1/roles/no-numerico')->assertNotFound();
    }

    // Flujo: Admin crea, edita y elimina un rol sin afectar otros roles ni sus usuarios.
    public function test_admin_can_create_edit_and_delete_an_unassigned_role(): void
    {
        // Preparación y request: crea un rol configurable con permisos de lectura.
        $this->admin();
        $created = $this->postJson('/api/v1/roles', ['nombre' => 'Operador de lotes', 'descripcion' => 'Consulta de producción', 'permisos' => ['can_view_flocks']])
            ->assertCreated()->assertJsonPath('data.version', 1);
        $id = $created->json('data.id');
        $key = $created->json('data.clave');

        // Requests: edita metadatos y después desactiva todos sus permisos.
        $this->patchJson('/api/v1/roles/'.$id, ['nombre' => 'Consulta de lotes', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.clave', $key);
        $this->putJson('/api/v1/roles/'.$id.'/permisos', ['permisos' => [], 'version' => 2])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.permisos', []);
        $this->getJson('/api/v1/roles/'.$id)->assertOk()->assertJsonPath('data.nombre', 'Consulta de lotes');
        $this->deleteJson('/api/v1/roles/'.$id, ['version' => 3])->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $id]);
        $this->assertSame(4, AuditEntry::query()->whereIn('event', ['role_created', 'role_updated', 'role_permissions_updated', 'role_deleted'])->count());
        $this->assertSame('Operador de lotes', AuditEntry::query()->where('event', 'role_created')->firstOrFail()->properties['subject_snapshot']['label']);
    }

    // Flujo: activar y desactivar un permiso cambia el acceso de todos los usuarios del mismo rol.
    public function test_permission_toggles_apply_to_all_assigned_users_without_reassignment(): void
    {
        // Preparación: carga relaciones y permisos antes de editar para detectar autorizaciones obsoletas.
        $admin = $this->admin();
        $role = $this->role(['can_view_flocks']);
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            $user->syncRoles([$role]);
            $user->load('roles.permissions');
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/flocks')->assertOk();
        }
        $assignments = \DB::table('model_has_roles')->where('role_id', $role->id)->orderBy('model_id')->get()->toArray();

        // Request: Admin desactiva el permiso y conserva la identidad y asignaciones del rol.
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => [], 'version' => 1])->assertOk();
        foreach ($users as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/flocks')->assertForbidden();
            $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.rol.id', $role->id)->assertJsonPath('data.permissions', []);
        }
        $this->assertEquals($assignments, \DB::table('model_has_roles')->where('role_id', $role->id)->orderBy('model_id')->get()->toArray());

        // Request: vuelve a activar el permiso en el mismo rol.
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => ['can_view_flocks'], 'version' => 2])->assertOk();
        foreach ($users as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/flocks')->assertOk();
        }
    }

    // Flujo: las selecciones inválidas no agregan permisos ni alteran el rol.
    public function test_invalid_permissions_dependencies_and_protected_fields_return_422(): void
    {
        // Preparación: utiliza un rol vacío para comprobar que los rechazos no cambian el estado.
        $this->admin();
        $role = $this->role([]);

        // Requests: intenta activar dependencias incompletas, permisos reservados y datos ajenos al contrato.
        foreach ([['can_manage_flocks'], ['can_adjust_customer_accounts'], ['can_manage_roles'], ['can_view_flocks', 'can_view_flocks']] as $permissions) {
            $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => $permissions, 'version' => 1])->assertUnprocessable();
        }
        $this->postJson('/api/v1/roles', ['nombre' => 'Intento', 'permisos' => [], 'guard_name' => 'api', 'name' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors(['guard_name', 'name']);
        $this->assertSame(0, $role->permissions()->count());
        $this->assertSame(1, $role->fresh()->version);
    }

    // Flujo: el control de versión evita perder cambios y las peticiones idénticas no duplican auditoría.
    public function test_stale_edits_return_409_and_identical_edits_do_not_create_audits(): void
    {
        // Preparación y request: modifica el rol y conserva una versión anterior.
        $this->admin();
        $role = $this->role([]);
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => ['can_view_flocks'], 'version' => 1])->assertOk();
        $this->patchJson('/api/v1/roles/'.$role->id, ['nombre' => 'Cambio obsoleto', 'version' => 1])->assertConflict();

        // Request: repetir el estado actual no incrementa la versión ni la auditoría.
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => ['can_view_flocks'], 'version' => 2])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(1, AuditEntry::query()->where('event', 'role_permissions_updated')->count());
    }

    // Flujo: los roles protegidos y los roles asignados no se eliminan desde la API.
    public function test_admin_role_is_immutable_and_assigned_roles_return_409_on_delete(): void
    {
        // Preparación: crea una asignación incluso para un usuario deshabilitado.
        $admin = $this->admin();
        $role = $this->role([]);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $user->delete();

        // Requests: Admin conserva su rol íntegro y el rol asignado conserva sus referencias.
        $this->putJson('/api/v1/roles/'.$admin->roles()->firstOrFail()->id.'/permisos', ['permisos' => [], 'version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/roles/'.Role::findByName('employee', 'web')->id, ['version' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/roles/'.$role->id, ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    // Flujo: no se retira ni desactiva al último administrador activo.
    public function test_last_admin_cannot_be_demoted_or_disabled(): void
    {
        // Preparación: existe un único Admin activo.
        $admin = $this->admin();
        $employee = Role::findByName('employee', 'web');

        // Requests: ambas operaciones fallan sin alterar cuenta o asignación.
        $this->putJson('/api/v1/usuarios/'.$admin->id.'/rol', ['rol_id' => $employee->id])->assertConflict();
        $this->patchJson('/api/v1/users/'.$admin->id.'/status', ['enabled' => false])->assertConflict();
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertFalse($admin->fresh()->trashed());
    }

    // Flujo: alta y cambios de usuario aceptan exactamente un rol y nunca permisos individuales.
    public function test_user_creation_and_assignment_use_a_single_role_and_are_audited(): void
    {
        // Preparación y request: crea una cuenta con un rol existente.
        $this->admin();
        $role = $this->role(['can_view_flocks']);
        $payload = ['name' => 'Usuario de prueba', 'email' => 'single-role@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'rol_id' => $role->id];
        $id = $this->postJson('/api/v1/users', $payload)->assertCreated()->assertJsonPath('data.rol.id', $role->id)->json('data.id');
        $this->assertSame(1, User::findOrFail($id)->roles()->count());
        $this->assertDatabaseCount('model_has_permissions', 0);

        // Requests: rechaza listas y cambia la única asignación mediante la ruta existente.
        $this->putJson('/api/v1/usuarios/'.$id.'/rol', ['rol_id' => [$role->id]])->assertUnprocessable();
        $this->putJson('/api/v1/usuarios/'.$id.'/rol', ['rol_id' => $role->id, 'permissions' => ['can_manage_flocks']])->assertUnprocessable();
        $employee = Role::findByName('employee', 'web');
        $this->putJson('/api/v1/users/'.$id.'/roles', ['rol_id' => $employee->id])->assertOk()->assertJsonPath('data.rol.id', $employee->id);
        $this->assertSame(1, User::findOrFail($id)->roles()->count());
        $this->assertFalse(User::findOrFail($id)->can('can_view_flocks'));
        $this->assertDatabaseHas('activity_log', ['event' => 'user_registered', 'subject_id' => $id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'user_role_updated', 'subject_id' => $id]);
    }

    // Flujo: el seeder no deshace las decisiones de Admin al reejecutarse.
    public function test_repeated_seeding_preserves_operational_and_custom_role_permissions(): void
    {
        // Preparación: Admin configura roles existentes y crea una agrupación personalizada.
        $this->admin();
        $employee = Role::findByName('employee', 'web');
        $employee->syncPermissions(['can_view_flocks']);
        $custom = $this->role(['can_view_feed_stock']);
        $delivery = Role::findByName('delivery', 'web');
        $delivery->syncPermissions([]);

        // Mutación: vuelve a cargar el catálogo sin restablecer configuraciones del cliente.
        $this->seed(IdentityPermissionSeeder::class);
        $this->assertSame(['can_view_flocks'], $employee->fresh()->permissions->pluck('name')->all());
        $this->assertSame(['can_view_feed_stock'], $custom->fresh()->permissions->pluck('name')->all());
        $this->assertSame(0, $delivery->fresh()->permissions()->count());
    }

    // Flujo: un fallo de auditoría revierte permisos, versión y revocación de sesiones.
    public function test_audit_failure_rolls_back_permissions_and_session_revocation(): void
    {
        // Preparación: crea una sesión válida y simula la falla del grabador.
        $admin = $this->admin();
        $role = $this->role(['can_login_native']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $token = $user->createToken('rollback', ['api:access'], now()->addDays(90));
        $session = app(AuthSessionService::class)->createPersonal($user, $token);
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                throw new RuntimeException('Falla de auditoría de prueba');
            }
        });

        // Request: el error no deja permisos retirados ni sesiones revocadas parcialmente.
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => [], 'version' => 1])->assertServerError();
        $this->assertSame(1, $role->fresh()->version);
        $this->assertTrue($user->can('can_login_native'));
        $this->assertNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    // Flujo: retirar el acceso nativo invalida tokens existentes y no requiere volver a iniciar sesión.
    public function test_removing_native_access_revokes_existing_tokens(): void
    {
        // Preparación: emite una sesión personal antes de retirar el permiso de acceso.
        $this->admin();
        $role = $this->role(['can_login_native']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $token = $user->createToken('revocation', ['api:access'], now()->addDays(90));
        $session = app(AuthSessionService::class)->createPersonal($user, $token);

        // Request: guarda la selección vacía y comprueba revocación síncrona.
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => [], 'version' => 1])->assertOk();
        $this->assertSame('permissions_changed', $session->fresh()->revoked_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    // Flujo: la restricción persistente impide acumular dos roles, aun fuera del controlador.
    public function test_database_rejects_two_roles_for_the_same_user(): void
    {
        // Preparación: cada cuenta de la factory tiene una única asignación inicial.
        $user = User::factory()->create();
        $other = Role::findOrCreate('other', 'web');

        // Mutación: intenta añadir un segundo rol sin retirar el existente.
        $this->expectException(QueryException::class);
        $user->assignRole($other);
    }

    // Flujo: la API del modelo impide otorgar permisos directamente a una persona.
    public function test_user_model_rejects_direct_permissions(): void
    {
        // Preparación y mutación: intenta eludir la configuración por rol.
        $user = User::factory()->create();
        $this->expectException(ValidationException::class);
        $user->givePermissionTo('can_view_flocks');
    }

    // Flujo: retirar acceso web invalida una cookie personal ya emitida.
    public function test_removing_web_access_revokes_existing_cookie_session(): void
    {
        // Preparación: crea una sesión web y un rol con acceso personal.
        $this->admin();
        $role = $this->role(['can_login_web', 'can_login_native']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $session = app(AuthSessionService::class)->createWebPersonal($user);

        // Request: desactiva sólo el canal web y comprueba su sesión anterior.
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => ['can_login_native'], 'version' => 1])->assertOk();
        $this->assertSame('permissions_changed', $session->fresh()->revoked_reason);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:4200')
            ->withSession(['auth_session_id' => $session->id])->getJson('/api/v1/me')->assertUnauthorized();
    }

    // Flujo: un rol personalizado habilita el canal compartido por permisos y puede perder ese acceso.
    public function test_custom_role_shared_access_and_revocation_do_not_require_employee_role_name(): void
    {
        // Preparación: configura PIN y sesión de un usuario con un único rol personalizado.
        $admin = $this->admin();
        config(['identity.pin.pepper' => 'test-only-temporary-value']);
        $role = $this->role(['can_login_shared']);
        $user = User::factory()->create(['pin_enabled' => true, 'pin_hash' => app(PinHasher::class)->hash('0007')]);
        $user->syncRoles([$role]);
        $secret = 'shared-role-test-credential';
        $device = SharedDevice::query()->create([
            'name' => 'Dispositivo de prueba', 'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(), 'enrolled_by' => $admin->id, 'enrolled_at' => now(),
        ]);
        $token = $user->createToken('shared', ['api:access'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($user, $device, $token);

        // Requests: el selector lo ofrece por sus permisos y retirarlos revoca la sesión y su PAT.
        $this->withHeader('X-Shared-Device-Token', $device->id.'|'.$secret)->getJson('/api/v1/shared-device/users')->assertOk()->assertJsonPath('data.0.id', $user->id);
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => [], 'version' => 1])->assertOk();
        $this->assertSame('permissions_changed', $session->fresh()->revoked_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->withHeader('X-Shared-Device-Token', $device->id.'|'.$secret)->getJson('/api/v1/shared-device/users')->assertOk()->assertJsonCount(0, 'data');
    }

    // Flujo: promover una cuenta a Admin elimina su PIN operativo y el contexto compartido anterior.
    public function test_promotion_to_admin_disables_pin_and_revokes_shared_sessions(): void
    {
        // Preparación: conserva una sesión compartida antes de promover la cuenta.
        $admin = $this->admin();
        $role = $this->role(['can_login_shared']);
        $user = User::factory()->create(['pin_enabled' => true, 'pin_hash' => 'test-only-hash']);
        $user->syncRoles([$role]);
        $device = SharedDevice::query()->create([
            'name' => 'Dispositivo de prueba', 'credential_hash' => hash('sha256', 'promotion-test'),
            'credential_expires_at' => now()->addYear(), 'enrolled_by' => $admin->id, 'enrolled_at' => now(),
        ]);
        $token = $user->createToken('shared', ['api:access'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($user, $device, $token);

        // Request: asigna Admin como único rol y comprueba los efectos de seguridad.
        $adminRole = Role::findByName('admin', 'web');
        $this->putJson('/api/v1/usuarios/'.$user->id.'/rol', ['rol_id' => $adminRole->id])->assertOk();
        $this->assertFalse($user->fresh()->pin_enabled);
        $this->assertNull($user->fresh()->pin_hash);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertSame(1, $user->roles()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    // Flujo: una cuenta deshabilitada con sesiones históricas no impide editar los permisos del rol.
    public function test_permission_edits_revoke_sessions_of_disabled_users(): void
    {
        // Preparación: mantiene una sesión histórica de una cuenta deshabilitada.
        $this->admin();
        $role = $this->role(['can_login_native']);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        $token = $user->createToken('disabled', ['api:access'], now()->addDay());
        $session = app(AuthSessionService::class)->createPersonal($user, $token);
        $user->delete();

        // Request: retira permisos del rol sin depender de una relación que excluye usuarios borrados.
        $this->putJson('/api/v1/roles/'.$role->id.'/permisos', ['permisos' => [], 'version' => 1])->assertOk();
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    // Flujo: el contrato publicado describe el catálogo y el rol singular entregados.
    public function test_role_openapi_contract_matches_the_delivered_resources(): void
    {
        // Preparación: analiza el contrato con el mismo lector utilizado por otras pruebas del proyecto.
        $this->admin();
        $contract = Yaml::parseFile(base_path('contracts/openapi/authentication.yaml'));
        $role = Role::findByName('employee', 'web');

        // Consultas: comprueba rutas, tamaño del catálogo y campos requeridos de la respuesta real.
        $response = $this->getJson('/api/v1/roles/'.$role->id)->assertOk();
        foreach ($contract['components']['schemas']['Role']['required'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data'));
        }
        $this->assertArrayHasKey('/api/v1/roles/{role}/permisos', $contract['paths']);
        $this->assertContains('rol', $contract['components']['schemas']['User']['required']);
        $this->assertSame(count(config('permission-catalog.permissions')), $contract['components']['schemas']['PermissionSelection']['maxItems']);
    }

    // Flujo: el catálogo se sincroniza incluso si la caché conserva identificadores anteriores al cambio.
    public function test_seeder_clears_stale_permission_cache_before_registering_catalog(): void
    {
        // Preparación: representa una caché calentada antes de renombrar permisos en una migración.
        $this->admin();
        $permission = Permission::findByName('can_view_feed_stock', 'web');
        Permission::query()->whereKey($permission->id)->update(['name' => 'legacy_feed_read']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findByName('legacy_feed_read', 'web');
        Permission::query()->whereKey($permission->id)->update(['name' => 'can_view_feed_stock']);

        // Mutación: sincroniza sin intentar insertar una identidad que ya existe en la base.
        $this->seed(IdentityPermissionSeeder::class);
        $this->assertSame($permission->id, Permission::findByName('can_view_feed_stock', 'web')->id);
        $this->assertSame(1, Permission::query()->where('name', 'can_view_feed_stock')->count());
        $this->assertTrue(Role::findByName('delivery', 'web')->hasPermissionTo('can_login_native'));
        $this->assertTrue(Role::findByName('delivery', 'web')->hasPermissionTo('can_start_deliveries'));
    }

    private function admin(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->syncRoles([Role::findByName('admin', 'web')]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param list<string> $permissions */
    private function role(array $permissions): Role
    {
        $role = Role::factory()->create(['label' => 'Operador']);
        $role->syncPermissions($permissions);

        return $role;
    }
}
