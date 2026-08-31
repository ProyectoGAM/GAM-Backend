<?php

namespace Tests\Feature\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\IdentityAndAccess\Application\Actions\RegisterUserAction;
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
        // Acción 1: registra el usuario mediante la API.
        $response = $this->postJson('/api/v1/autenticacion/registro', [
            'nombre' => 'Audited User',
            'correo_electronico' => 'audited.user@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
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
        $this->assertNull($entry->causer_id);
        $this->assertNotSame('', $entry->operation_id);
        $this->assertSame($response->headers->get('X-Trace-Id'), $entry->trace_id);
        $this->assertSame([
            'name' => 'Audited User',
            'email' => 'audited.user@example.test',
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
            ->postJson('/api/v1/autenticacion/cerrar-sesion')
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
        $permission = Permission::findOrCreate('audit.view', 'web');
        $user->givePermissionTo($permission);
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

        $this->getJson('/api/v1/auditoria/entradas?event=stock_moved&por_pagina=10')
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

        $this->getJson('/api/v1/auditoria/entradas')->assertForbidden();
    }

    // Flujo: fuerza un fallo de auditoría; verifica que la operación de negocio se revierte.
    public function test_failed_audit_rolls_back_the_business_operation(): void
    {
        // Preparación: sustituye el grabador por uno que falla.
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
            ]);

            $this->fail('The registration should fail when audit storage fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        // Verificación: confirma que el usuario no se persistió.
        $this->assertDatabaseMissing('users', ['email' => 'rolled.back@example.test']);
    }
}
