<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\PublishNotificationAction;
use App\DTO\Notifications\NotificationData;
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
            ->assertJsonPath('data.0.id', $new->public_id)->assertJsonPath('meta.total', 2);
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

    // Una publicación revertida no deja registro ni señal, y el reintento crea una sola fila.
    public function test_publication_is_transactional_and_idempotent(): void
    {
        // Preparación: crea el destinatario, la clave de origen y un observador de señales.
        $user = $this->administrator();
        Event::fake([NotificationInboxChanged::class]);
        $occurredAt = Carbon::parse('2026-11-05T09:00:00-03:00');
        $data = new NotificationData($user->id, 'test', 'source-001', 'Aviso', 'Prueba', $occurredAt);
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
        $this->assertSame($occurredAt->timestamp, $notification->fresh()->occurred_at->timestamp);
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
        $user->roles()->firstOrFail()->givePermissionTo(['can_login_shared', 'can_view_notifications']);
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
