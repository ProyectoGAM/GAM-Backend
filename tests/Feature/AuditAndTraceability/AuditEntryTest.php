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

    public function test_registration_records_an_append_only_audit_entry_with_trace_context(): void
    {
        $response = $this->postJson('/api/v1/autenticacion/registro', [
            'name' => 'Audited User',
            'email' => 'audited.user@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ]);

        $response->assertCreated()->assertHeader('X-Trace-Id');

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

    public function test_logout_records_the_authenticated_actor_and_revokes_the_token_atomically(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('audit-logout-test', ['api:access']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/autenticacion/cerrar-sesion')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'user_logged_out',
            'causer_type' => User::class,
            'causer_id' => $user->getKey(),
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
        ]);
    }

    public function test_user_with_audit_permission_can_read_paginated_entries(): void
    {
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

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auditoria/entradas?event=stock_moved&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'stock_moved')
            ->assertJsonPath('data.0.description', 'Movimiento de stock realizado')
            ->assertJsonPath('data.0.properties.quantity', 12)
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_user_without_audit_permission_cannot_read_entries(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/auditoria/entradas')->assertForbidden();
    }

    public function test_failed_audit_rolls_back_the_business_operation(): void
    {
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                throw new RuntimeException('Audit storage failed.');
            }
        });

        try {
            $this->app->make(RegisterUserAction::class)->execute([
                'name' => 'Rolled Back User',
                'email' => 'rolled.back@example.test',
                'password' => 'correct-password',
            ]);

            $this->fail('The registration should fail when audit storage fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rolled.back@example.test']);
    }
}
