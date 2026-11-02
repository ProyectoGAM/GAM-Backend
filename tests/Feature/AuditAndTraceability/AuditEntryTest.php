<?php

namespace Tests\Feature\AuditAndTraceability;

use App\Actions\IdentityAndAccess\RegisterUserAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AuditEntryTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: registra un usuario y comprueba la auditoría con trazabilidad y datos protegidos.
    public function test_registration_records_an_append_only_audit_entry_with_trace_context(): void
    {
        // Acción 1: crea un administrador de prueba y registra el usuario mediante la API administrativa.
        $admin = User::factory()->create();
        $this->seed(IdentityPermissionSeeder::class);
        $admin->syncRoles([Role::findByName('admin', 'web')]);
        Sanctum::actingAs($admin, ['api:access']);
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Audited User',
            'email' => 'audited.user@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'rol_id' => Role::findByName('employee', 'web')->id,
        ]);

        // Verificación: confirma respuesta, usuario creado y entrada de auditoría.
        $response->assertCreated()->assertHeader('X-Trace-Id');

        // Acción 2: consulta el usuario y su registro de auditoría.
        $user = User::query()->where('email', 'audited.user@example.test')->firstOrFail();
        $entry = AuditEntry::query()
            ->where('event', 'user_registered')
            ->where('subject_id', $user->getKey())
            ->firstOrFail();

        $this->assertSame('identity', $entry->log_name);
        $this->assertSame('api', $entry->source);
        $this->assertSame(User::class, $entry->subject_type);
        $this->assertSame($admin->getKey(), $entry->causer_id);
        $this->assertNotSame('', $entry->operation_id);
        $this->assertSame($response->headers->get('X-Trace-Id'), $entry->trace_id);
        $this->assertSame([
            'name' => 'Audited User',
            'email' => 'audited.user@example.test',
            'role' => 'employee',
        ], $entry->properties->get('subject_snapshot'));
        $this->assertArrayNotHasKey('password', $entry->properties->toArray());
    }

    // Flujo: cierra una sesión; comprueba revocación del token y auditoría atómica.
    public function test_logout_records_the_authenticated_actor_and_revokes_the_token_atomically(): void
    {
        // Preparación: crea el usuario y su token de acceso.
        $user = User::factory()->create();
        $token = $user->createToken('audit-logout-test', ['api:access']);

        // Acción: cierra la sesión autenticada.
        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Verificación: confirma revocación del token y auditoría del actor.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'user_logged_out',
            'causer_type' => User::class,
            'causer_id' => $user->getKey(),
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
        ]);
    }

    // Flujo: crea una entrada y la consulta con permiso, filtros y paginación.
    public function test_user_with_audit_permission_can_read_paginated_entries(): void
    {
        // Preparación: crea el usuario autorizado, el sujeto y una entrada de auditoría.
        $user = User::factory()->create();
        $permission = Permission::findOrCreate('can_view_audit', 'web');
        $user->roles()->firstOrFail()->givePermissionTo($permission);
        $subject = User::factory()->create();

        AuditEntry::query()->create([
            'log_name' => 'inventory',
            'description' => 'Movimiento de stock realizado',
            'subject_type' => User::class,
            'subject_id' => $subject->getKey(),
            'event' => 'stock_moved',
            'causer_type' => User::class,
            'causer_id' => $user->getKey(),
            'operation_id' => Str::uuid()->toString(),
            'trace_id' => Str::uuid()->toString(),
            'source' => 'test',
            'properties' => ['quantity' => 12],
        ]);

        // Acción: autentica al usuario y consulta las entradas filtradas.
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/audit/entries?event=stock_moved&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'stock_moved')
            ->assertJsonPath('data.0.description', 'Movimiento de stock realizado')
            ->assertJsonPath('data.0.properties.quantity', 12)
            ->assertJsonPath('meta.per_page', 10);
    }

    // Flujo: autentica un usuario sin permiso y verifica que la lectura es rechazada.
    public function test_user_without_audit_permission_cannot_read_entries(): void
    {
        // Acción: intenta consultar la auditoría sin autorización.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/audit/entries')->assertForbidden();
    }

    // Flujo: fuerza un fallo de auditoría; verifica que la operación de negocio se revierte.
    public function test_failed_audit_rolls_back_the_business_operation(): void
    {
        // Preparación: autoriza el alta y sustituye el grabador por uno que falla.
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                throw new RuntimeException('Audit storage failed.');
            }
        });

        try {
            // Acción: intenta registrar un usuario con auditoría no disponible.
            $this->app->make(RegisterUserAction::class)->execute([
                'name' => 'Rolled Back User',
                'email' => 'rolled.back@example.test',
                'password' => 'correct-password',
                'rol_id' => Role::findByName('employee', 'web')->id,
            ], $admin);

            $this->fail('The registration should fail when audit storage fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        // Verificación: confirma que el usuario no se persistió.
        $this->assertDatabaseMissing('users', ['email' => 'rolled.back@example.test']);
    }

    // Flujo: clasifica planes antiguos sin alterar la historia y conserva filtros, totales y paginación.
    public function test_management_plan_history_belongs_to_management_and_not_lots(): void
    {
        // Preparación: crea un lector y eventos de planes antiguos, actuales y de otros módulos.
        $user = User::factory()->create();
        $user->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_view_audit', 'web'));
        $planEvents = ['plan_template_created', 'plan_template_revised', 'plan_template_published',
            'plan_template_retired', 'plan_template_activated', 'flock_plan_assigned',
            'flock_plan_inherited', 'flock_plan_revised'];
        $records = [];
        foreach ($planEvents as $event) {
            $records[] = ['lots', $event];
        }
        $records = [...$records, ['management_plans', 'medicine_application_recorded'],
            ['lots', 'flock_created'], ['lots', 'eggs_collected'], ['lots', null],
            ['lots', 'future_plan_event'], ['inventory', 'flock_feeding_plan_revised']];
        $entries = [];
        foreach ($records as [$module, $event]) {
            $entries[] = AuditEntry::query()->create([
                'log_name' => $module, 'event' => $event, 'description' => 'Actividad de prueba',
                'operation_id' => Str::uuid()->toString(), 'source' => 'test',
                'causer_type' => User::class, 'causer_id' => $user->getKey(), 'properties' => [],
            ]);
        }
        Sanctum::actingAs($user, ['*']);

        // Consulta: Lotes conserva sus eventos, incluidos los desconocidos y los que no tienen código.
        $lots = $this->getJson('/api/v1/audit/entries?log_name=lots&per_page=2')
            ->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data');
        $this->assertSame(['lots'], array_values(array_unique(array_column($lots->json('data'), 'log_name'))));

        // Consulta: Manejo incorpora los ocho eventos antiguos y conserva su paginación real.
        $management = $this->getJson('/api/v1/audit/entries?log_name=management_plans&per_page=3&page=2')
            ->assertOk()->assertJsonPath('meta.total', 9)->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)->assertJsonCount(3, 'data');
        $this->assertSame(['management_plans'], array_values(array_unique(array_column($management->json('data'), 'log_name'))));

        // Consulta: otros filtros siguen acotando los eventos reclasificados y no incluyen alimentación.
        $this->getJson('/api/v1/audit/entries?log_name=management_plans&event=flock_plan_assigned')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'flock_plan_assigned');
        $this->getJson('/api/v1/audit/entries?log_name=lots&event=plan_template_published')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/audit/entries?log_name=inventory')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.log_name', 'inventory');
        $this->getJson('/api/v1/audit/entries?log_name=management_plans&source=other')
            ->assertOk()->assertJsonPath('meta.total', 0);

        // Consulta: Todo el sistema presenta el mismo módulo sin duplicar registros.
        $all = $this->getJson('/api/v1/audit/entries?per_page=100')
            ->assertOk()->assertJsonPath('meta.total', 14)->assertJsonCount(14, 'data');
        $byId = array_column($all->json('data'), null, 'id');
        foreach (array_slice($entries, 0, 8) as $entry) {
            $this->assertSame('management_plans', $byId[$entry->getKey()]['log_name']);
            $this->assertDatabaseHas('activity_log', ['id' => $entry->getKey(), 'log_name' => 'lots']);
        }
    }
}
