<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\PublishNotificationAction;
use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Events\Notifications\NotificationInboxChanged;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Notifications\AppNotification;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\Notifications\NotificationChannel;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NotificationEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Consulta y marca como leídas únicamente las notificaciones del destinatario.
    public function test_inbox_scopes_listing_count_and_reads_to_the_recipient(): void
    {
        // Preparación: crea dos destinatarios y mensajes con fechas diferentes.
        $user = $this->administrator();
        $old = AppNotification::factory()->create(['recipient_id' => $user->id, 'occurred_at' => now()->subDay()]);
        $new = AppNotification::factory()->create(['recipient_id' => $user->id]);
        $foreign = AppNotification::factory()->create();

        // Consulta: verifica orden, paginación, contador y aislamiento de accesos directos.
        $this->getJson('/api/v1/notificaciones?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $new->public_id)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.urgencia', 'bajo')->assertJsonPath('data.0.modulo', 'general')
            ->assertJsonPath('data.0.estado_resolucion', null)->assertJsonPath('data.0.resuelta_en', null);
        $this->getJson('/api/v1/notificaciones/resumen')->assertOk()->assertJsonPath('data.no_leidas', 2);
        $this->getJson('/api/v1/notificaciones/'.$foreign->public_id)->assertNotFound();
        $this->patchJson('/api/v1/notificaciones/'.$foreign->public_id.'/lectura')->assertNotFound();

        // Mutación: repite el marcado para comprobar que se conserva la primera fecha de lectura.
        $readUrl = '/api/v1/notificaciones/'.$new->public_id.'/lectura';
        $readAt = $this->patchJson($readUrl)->assertOk()->json('data.leida_en');
        $this->travel(1)->minute();
        $this->patchJson($readUrl)->assertOk()->assertJsonPath('data.leida_en', $readAt);

        // Consulta: comprueba que el filtro y el contador reflejan el marcado.
        $this->getJson('/api/v1/notificaciones?estado_lectura=no_leida')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->public_id);
        $this->getJson('/api/v1/notificaciones/resumen')->assertOk()->assertJsonPath('data.no_leidas', 1);
    }

    // Los filtros de resolución separan los avisos vigentes de las alertas resueltas antes de paginar.
    public function test_inbox_can_filter_active_and_resolved_notifications(): void
    {
        // Preparación: crea un aviso informativo leído, una alerta pendiente y una alerta resuelta.
        $user = $this->administrator();
        $informative = AppNotification::factory()->create([
            'recipient_id' => $user->id, 'read_at' => now(), 'occurred_at' => now()->subDay(),
        ]);
        $pending = AppNotification::factory()->create([
            'recipient_id' => $user->id, 'is_alert' => true, 'occurred_at' => now()->subHour(),
        ]);
        $resolved = AppNotification::factory()->create([
            'recipient_id' => $user->id, 'is_alert' => true, 'resolved_at' => now(),
        ]);

        // Consulta: conserva todos los avisos al omitir el filtro y cuenta los vigentes antes de paginar.
        $this->getJson('/api/v1/notificaciones')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/notificaciones?estado_resolucion=vigente&per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $pending->public_id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/notificaciones?estado_resolucion=vigente&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $informative->public_id)->assertJsonPath('data.0.estado_resolucion', null);

        // Consulta: combina resolución con lectura y valida los valores del filtro.
        $this->getJson('/api/v1/notificaciones?estado_resolucion=resuelto&estado_lectura=no_leida')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $resolved->public_id);
        $this->getJson('/api/v1/notificaciones?estado_resolucion=no_resuelto')
            ->assertUnprocessable()->assertJsonValidationErrors('estado_resolucion');
    }

    // La lectura y la resolución de un aviso de alerta evolucionan de forma independiente.
    public function test_alert_resolution_is_separate_from_reading_and_keeps_its_first_timestamp(): void
    {
        // Preparación: publica un aviso operativo y arma las rutas de la bandeja.
        $user = $this->administrator();
        $alert = app(PublishNotificationAction::class)->execute(new NotificationData(
            $user->id, 'test', 'alert-001', 'Alerta de prueba', 'Requiere seguimiento.', now(),
            module: 'management_plans', isAlert: true,
        ));
        $detail = '/api/v1/notificaciones/'.$alert->public_id;

        // Consulta y mutación: un aviso pendiente sigue pendiente después de leerlo.
        $this->getJson($detail)->assertOk()
            ->assertJsonPath('data.estado_resolucion', 'no_resuelto')->assertJsonPath('data.resuelta_en', null);
        $readAt = $this->patchJson($detail.'/lectura')->assertOk()
            ->assertJsonPath('data.estado_resolucion', 'no_resuelto')->json('data.leida_en');
        $this->assertIsString($readAt);

        // Mutación: el módulo resuelve el aviso y reintenta sin cambiar la fecha ni volver a emitir.
        Event::fake([NotificationInboxChanged::class]);
        $resolver = app(ResolveAlertNotificationAction::class);
        $resolvedAt = $resolver->execute($alert)->resolved_at->toIso8601String();
        $this->travel(1)->minute();
        $resolver->execute($alert);
        Event::assertDispatchedTimes(NotificationInboxChanged::class, 1);

        // Consulta: la bandeja conserva los dos estados y sus fechas.
        $this->getJson('/api/v1/notificaciones')->assertOk()
            ->assertJsonPath('data.0.estado_resolucion', 'resuelto')
            ->assertJsonPath('data.0.resuelta_en', $resolvedAt)
            ->assertJsonPath('data.0.leida_en', $readAt);
    }

    // Filtra por origen y urgencia, y aplica el orden de prioridad antes de paginar.
    public function test_inbox_can_filter_and_sort_by_urgency_and_source_module(): void
    {
        // Preparación: crea avisos de distintos módulos, urgencias y fechas.
        $user = $this->administrator();
        $newest = AppNotification::factory()->create(['recipient_id' => $user->id]);
        $medium = AppNotification::factory()->create([
            'recipient_id' => $user->id, 'module' => 'deliveries',
            'urgency' => NotificationUrgency::Medium, 'occurred_at' => now()->subDay(),
        ]);
        $high = AppNotification::factory()->create([
            'recipient_id' => $user->id, 'module' => 'management_plans',
            'urgency' => NotificationUrgency::High, 'occurred_at' => now()->subDays(2),
        ]);
        $oldHigh = AppNotification::factory()->create([
            'recipient_id' => $user->id, 'module' => 'deliveries',
            'urgency' => NotificationUrgency::High, 'occurred_at' => now()->subDays(3),
        ]);

        // Consulta: comprueba el orden cronológico habitual y el orden opcional por urgencia.
        $this->getJson('/api/v1/notificaciones')->assertOk()->assertJsonPath('data.0.id', $newest->public_id);
        $this->getJson('/api/v1/notificaciones?orden=urgencia&per_page=3')->assertOk()
            ->assertJsonPath('data.0.id', $high->public_id)
            ->assertJsonPath('data.1.id', $oldHigh->public_id)
            ->assertJsonPath('data.2.id', $medium->public_id)
            ->assertJsonPath('meta.total', 4);

        // Consulta: combina filtros y verifica la clasificación también en el detalle.
        $this->getJson('/api/v1/notificaciones?urgencia=alto&modulo=deliveries')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $oldHigh->public_id);
        $this->getJson('/api/v1/notificaciones/'.$high->public_id)->assertOk()
            ->assertJsonPath('data.urgencia', 'alto')->assertJsonPath('data.modulo', 'management_plans');

        // Consulta: rechaza filtros fuera del contrato.
        $this->getJson('/api/v1/notificaciones?urgencia=critico&modulo=Planes&orden=titulo')
            ->assertUnprocessable()->assertJsonValidationErrors(['urgencia', 'modulo', 'orden']);
    }

    // Una publicación revertida no deja registro ni señal, y el reintento crea una sola fila.
    public function test_publication_is_transactional_and_idempotent(): void
    {
        // Preparación: crea el destinatario, la clave de origen y un observador de señales.
        $user = $this->administrator();
        Event::fake([NotificationInboxChanged::class]);
        $occurredAt = Carbon::parse('2026-11-05T09:00:00-03:00');
        $data = new NotificationData(
            $user->id, 'test', 'source-001', 'Aviso', 'Prueba', $occurredAt,
            urgency: NotificationUrgency::High, module: 'management_plans',
        );
        $publisher = app(PublishNotificationAction::class);

        // Mutación: revierte la primera publicación antes de que pueda emitir.
        DB::beginTransaction();
        $publisher->execute($data);
        DB::rollBack();
        $this->assertDatabaseCount('app_notifications', 0);
        Event::assertNotDispatched(NotificationInboxChanged::class);

        // Mutación: repite la publicación confirmada con la misma clave.
        $notification = $publisher->execute($data);
        $this->assertTrue($publisher->execute($data)->is($notification));
        $stored = $notification->fresh();
        $this->assertSame($occurredAt->timestamp, $stored->occurred_at->timestamp);
        $this->assertSame(NotificationUrgency::High, $stored->urgency);
        $this->assertSame('management_plans', $stored->module);
        $this->assertDatabaseCount('app_notifications', 1);
        Event::assertDispatchedTimes(NotificationInboxChanged::class, 1);
    }

    // La autorización solo firma el canal de la sesión vigente del destinatario.
    public function test_channel_authorization_requires_a_matching_current_session(): void
    {
        // Preparación: habilita un servidor de prueba y asocia un token real al usuario.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-app-key',
            'broadcasting.connections.reverb.secret' => 'test-app-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        $user = $this->administrator();
        $token = $user->createToken('prueba', ['api:access'], now()->addHour());
        $session = app(AuthSessionService::class)->createPersonal($user, $token);
        $user->withAccessToken($token->accessToken);

        // Consulta: obtiene el nombre privado de la sesión actual.
        $channel = $this->getJson('/api/v1/notificaciones/resumen')->assertOk()
            ->assertJsonPath('data.tiempo_real.clave', 'test-app-key')->json('data.tiempo_real.canal');
        $request = ['socket_id' => '123.456', 'channel_name' => 'private-'.$channel];

        // Solicitud: firma el canal propio y rechaza uno ajeno.
        $signature = hash_hmac('sha256', '123.456:'.$request['channel_name'], 'test-app-secret');
        $this->postJson('/api/v1/notificaciones/canal', $request)->assertOk()
            ->assertJsonPath('auth', 'test-app-key:'.$signature);
        $this->postJson('/api/v1/notificaciones/canal', [...$request, 'channel_name' => 'private-notifications.'.str_repeat('0', 64)])
            ->assertForbidden();

        // Mutación: revoca la sesión y comprueba la exclusión de emisiones posteriores.
        $session->forceFill(['revoked_at' => now()])->save();
        $this->postJson('/api/v1/notificaciones/canal', $request)->assertUnauthorized();
        $this->assertSame([], app(NotificationChannel::class)->forRecipient($user->id, null));
    }

    // La bandeja y la señal de un dispositivo compartido respetan su UP y generación.
    public function test_shared_session_cannot_read_or_receive_notifications_for_another_unit(): void
    {
        // Preparación: crea un empleado, su dispositivo y una sesión real con cabeceras de dispositivo.
        config(['identity.pin.pepper' => 'notification-test-pepper']);
        $this->seed(IdentityPermissionSeeder::class);
        $unit = ProductionUnit::factory()->create();
        $otherUnit = ProductionUnit::factory()->create();
        $user = User::factory()->devicePin($unit)->create();
        $user->roles()->firstOrFail()->givePermissionTo(['can_login_shared', 'can_view_notifications', 'can_manage_alert_thresholds']);
        $secret = 'notification-device-test-secret';
        $device = SharedDevice::query()->create([
            'name' => 'Equipo de prueba', 'production_unit_id' => $unit->id, 'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addHour(), 'enrolled_by' => $user->id, 'enrolled_at' => now(),
        ]);
        $token = $user->createToken('prueba', ['shared:employee'], now()->addHour());
        $session = app(AuthSessionService::class)->createShared($user, $device->refresh(), $token);
        $this->withToken($token->plainTextToken)->withHeaders([
            'X-Shared-Device-Token' => $device->id.'|'.$secret, 'X-GAM-Session' => $session->id,
        ]);
        $own = AppNotification::factory()->create(['recipient_id' => $user->id, 'production_unit_id' => $unit->id]);
        $foreign = AppNotification::factory()->create(['recipient_id' => $user->id, 'production_unit_id' => $otherUnit->id]);

        // Consulta: las respuestas y las emisiones excluyen la otra unidad.
        $this->getJson('/api/v1/notificaciones')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->public_id);
        $this->getJson('/api/v1/notificaciones/resumen')->assertOk()->assertJsonPath('data.no_leidas', 1);
        $this->getJson('/api/v1/notificaciones/'.$foreign->public_id)->assertNotFound();
        $this->patchJson('/api/v1/notificaciones/'.$foreign->public_id.'/lectura')->assertNotFound();
        $channels = app(NotificationChannel::class);
        $this->assertCount(1, $channels->forRecipient($user->id, $unit->id));
        $this->assertSame([], $channels->forRecipient($user->id, $otherUnit->id));

        // Solicitud: el permiso de configuración permite editar solo la unidad de la sesión.
        $ownThresholds = '/api/v1/unidades-productivas/'.$unit->id.'/umbrales-alertas/daily_egg_collection_missing';
        $foreignThresholds = '/api/v1/unidades-productivas/'.$otherUnit->id.'/umbrales-alertas/daily_egg_collection_missing';
        $thresholds = ['bajo' => 2, 'medio' => 4, 'alto' => 8];
        $this->putJson($ownThresholds, $thresholds)->assertOk()->assertJsonPath('data.alto', 8);
        $this->getJson($foreignThresholds)->assertNotFound();
        $this->putJson($foreignThresholds, $thresholds)->assertNotFound();

        // Mutación: cambia la generación del dispositivo y comprueba el descarte del canal.
        $device->increment('session_generation');
        $this->assertSame([], $channels->forRecipient($user->id, $unit->id));
    }

    // Prepara una cuenta administrativa autorizada para las consultas personales.
    private function administrator(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->syncRoles(['admin']);
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }
}
