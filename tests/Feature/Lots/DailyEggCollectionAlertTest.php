<?php

namespace Tests\Feature\Lots;

use App\Enums\Notifications\NotificationUrgency;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\FlockMovement;
use App\Models\Notifications\AppNotification;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;

final class DailyEggCollectionAlertTest extends LotsTestCase
{
    // Flujo: el aviso escala con las jornadas cerradas y se resuelve con una recolección vigente.
    public function test_daily_egg_collection_alert_escalates_and_reopens_if_the_only_collection_is_cancelled(): void
    {
        // Preparación: establece un lote en la jornada de hoy.
        $start = CarbonImmutable::parse('2026-10-20 12:00:00', config('lots.timezone'));
        $this->travelTo($start);
        $actor = $this->signIn(['can_view_egg_collections', 'can_manage_egg_collections', 'can_view_notifications']);
        $flock = $this->flock();
        $flock->forceFill(['established_at' => $start, 'entry_date' => $start->toDateString()])->save();
        FlockMovement::factory()->create([
            'destination_flock_id' => $flock->id, 'quantity' => $flock->initial_quantity,
            'occurred_at' => $start, 'created_by' => $actor->id,
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id, 'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id, 'current_quantity' => $flock->current_quantity,
                'entry_date' => $start->toDateString(),
            ]],
        ]);

        // Consulta: la jornada abierta se puede completar hasta la medianoche local.
        $this->travelTo($start->endOfDay());
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo($start->addDay()->startOfDay());
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame(NotificationUrgency::Low, $notice->urgency);
        $this->assertSame($flock->public_id, $notice->entity_id);
        $this->assertSame('lots', $notice->module);
        $this->assertSame('daily_egg_collection_missing', $notice->type);

        // Request: lee el aviso y comprueba que vuelve a no leído al subir de prioridad.
        $noticeUrl = '/api/v1/notificaciones/'.$notice->public_id;
        $this->patchJson($noticeUrl.'/lectura')->assertOk();
        $this->travelTo($start->addDays(2));
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->getJson($noticeUrl)->assertOk()
            ->assertJsonPath('data.urgencia', 'medio')->assertJsonPath('data.leida_en', null)
            ->assertJsonPath('data.entidad.ruta', '/administracion/lotes/lotes/'.$flock->public_id.'/recoleccion');
        $this->travelTo($start->addDays(6));
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertSame(NotificationUrgency::Medium, $notice->refresh()->urgency);
        $this->travelTo($start->addDays(7));
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertSame(NotificationUrgency::High, $notice->refresh()->urgency);
        $this->assertDatabaseCount('app_notifications', 1);

        // Request: registra la recolección cerca de medianoche local y comprueba que el aviso se resuelve.
        $this->travelTo($start->addDays(7)->setTime(23, 30));
        $created = $this->command('POST', '/flocks/'.$flock->public_id.'/collections', [
            'quantity' => 12,
        ])->assertCreated();
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->getJson($noticeUrl)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');

        // Consulta: la siguiente racha comienza el día posterior a la fecha local de recolección.
        $this->travelTo($start->addDays(8)->startOfDay());
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 1);
        $this->travelTo($start->addDays(9)->startOfDay());
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertSame(NotificationUrgency::Low, AppNotification::query()->whereNull('resolved_at')->sole()->urgency);

        // Request: anula la única recolección vigente y comprueba que vuelve a contar la racha original.
        $this->command('POST', '/collections/'.$created->json('data.collection.id').'/cancellation', [
            'version' => $created->json('data.collection.version'), 'correction_reason' => 'Error de captura.',
        ])->assertOk();
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 3);
        $this->assertSame(NotificationUrgency::High, AppNotification::query()->whereNull('resolved_at')->sole()->urgency);
    }

    // Flujo: configura la escala por tipo y UP, con permiso y auditoría idempotente.
    public function test_threshold_settings_require_permission_and_change_the_daily_and_planned_alerts(): void
    {
        // Preparación: crea un lote con 30 jornadas sin recolección y una vacuna prevista en cinco días.
        $start = CarbonImmutable::parse('2026-10-20 12:00:00', config('lots.timezone'));
        $this->travelTo($start);
        $actor = $this->signIn(['can_view_egg_collections', 'can_view_management_plans', 'can_view_notifications']);
        $flock = $this->flockWithPlan(activities: [[
            'type' => 'vaccination', 'title' => 'Vacuna de prueba', 'timing_kind' => 'day', 'start_day' => 36, 'start_week' => null,
        ]]);
        $path = '/api/v1/unidades-productivas/'.$flock->production_unit_id.'/umbrales-alertas/';
        $daily = ['bajo' => 31, 'medio' => 40, 'alto' => 50];
        $vaccination = ['bajo' => -5, 'medio' => -3, 'alto' => 1];

        // Request: ver recolecciones y notificaciones no concede edición de umbrales.
        $this->putJson($path.'daily_egg_collection_missing', $daily)->assertForbidden();
        $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_manage_alert_thresholds', 'web'));
        $this->getJson($path.'daily_egg_collection_missing')->assertOk()->assertJsonPath('data.alto', 7);
        $this->putJson($path.'daily_egg_collection_missing', ['bajo' => 0, 'medio' => 1, 'alto' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('bajo');
        $this->putJson($path.'daily_egg_collection_missing', ['bajo' => 1, 'medio' => 3, 'alto' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('alto');

        // Request: guarda valores distintos y verifica que otro tipo y otra UP mantienen sus propios valores.
        $this->putJson($path.'daily_egg_collection_missing', $daily)->assertOk()->assertJsonPath('data.bajo', 31);
        $this->putJson($path.'daily_egg_collection_missing', $daily)->assertOk();
        $this->putJson($path.'vaccination_due', $vaccination)->assertOk()->assertJsonPath('data.medio', -3);
        $this->getJson($path.'medication_due')->assertOk()->assertJsonPath('data.medio', -7);
        $otherUnit = ProductionUnit::factory()->create();
        $this->getJson('/api/v1/unidades-productivas/'.$otherUnit->id.'/umbrales-alertas/daily_egg_collection_missing')
            ->assertOk()->assertJsonPath('data.bajo', 1);
        $this->assertSame(2, AuditEntry::query()->where('event', 'alert_timing_thresholds_changed')->where('up_id', $flock->production_unit_id)->count());

        // Consulta: las evaluaciones aplican los umbrales y avisan cuando se alcanza el nuevo límite.
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->artisan('manejo:evaluar-sanidad')->assertSuccessful();
        $this->assertSame(NotificationUrgency::Low, AppNotification::query()->where('type', 'vaccination_due')->sole()->urgency);
        $this->travelTo($start->addDay());
        $this->artisan('lotes:evaluar-recolecciones-diarias')->assertSuccessful();
        $this->assertSame(NotificationUrgency::Low, AppNotification::query()->where('type', 'daily_egg_collection_missing')->sole()->urgency);
    }
}
