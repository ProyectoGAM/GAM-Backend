<?php

namespace Tests\Feature\ManagementPlans;

use App\Enums\Notifications\NotificationUrgency;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\Notifications\AppNotification;
use App\Models\SuppliersAndCatalogs\Medicine;
use Tests\Feature\Lots\LotsTestCase;

final class MedicationAlertTest extends LotsTestCase
{
    // Flujo: la medicación escala al vencimiento, se resuelve al aplicarse y vuelve a avisar tras una anulación.
    public function test_scheduled_medication_alert_follows_its_own_application_history(): void
    {
        // Preparación: fija la fecha local y programa una medicación y una vacuna para el mismo día.
        $start = now(config('lots.timezone'))->startOfDay()->addHours(12)->toImmutable();
        $this->travelTo($start);
        $this->signIn(['can_view_management_plans', 'can_execute_management_plans', 'can_view_notifications']);
        $medicine = Medicine::factory()->create();
        $flock = $this->flockWithPlan(activities: [
            [
                'type' => 'medication', 'title' => 'Medicación prevista', 'timing_kind' => 'day',
                'start_day' => 39, 'start_week' => null, 'catalog_type' => 'medicine', 'catalog_id' => $medicine->id,
                'catalog_snapshot' => ['id' => $medicine->public_id],
            ],
            [
                'type' => 'vaccination', 'title' => 'Vacuna prevista', 'timing_kind' => 'day',
                'start_day' => 39, 'start_week' => null,
            ],
        ]);
        $activity = FlockPlanActivity::query()->where('type', 'medication')->sole();

        // Consulta: el primer aviso se publica cuando faltan siete días.
        $this->artisan('manejo:evaluar-sanidad')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo($start->addDay());
        $this->artisan('manejo:evaluar-sanidad')->assertSuccessful();
        $notice = AppNotification::query()->where('type', 'medication_due')->sole();
        $vaccination = AppNotification::query()->where('type', 'vaccination_due')->sole();
        $this->assertSame(NotificationUrgency::Medium, $notice->urgency);

        // Mutación: lee el aviso y comprueba que la misma notificación vuelve a quedar sin leer al vencer.
        $url = '/api/v1/notificaciones/'.$notice->public_id;
        $this->patchJson($url.'/lectura')->assertOk();
        $this->travelTo($start->addDays(8));
        $this->artisan('manejo:evaluar-sanidad')->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.leida_en', null)->assertJsonPath('data.estado_resolucion', 'no_resuelto')
            ->assertJsonPath('data.tipo', 'medication_due')->assertJsonPath('data.modulo', 'management_plans')
            ->assertJsonPath('data.entidad.id', $activity->public_id);
        $this->assertDatabaseCount('app_notifications', 2);

        // Request: registra la aplicación vinculada y comprueba que solo se resuelve la medicación.
        $applicationId = $this->command('POST', '/flocks/'.$flock->public_id.'/medicaciones', [
            'medicine_id' => $medicine->public_id, 'plan_activity_id' => $activity->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated()->json('data.medicine_application.id');
        $this->artisan('manejo:evaluar-sanidad')->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');
        $this->assertNull($vaccination->refresh()->resolved_at);

        // Request: anula la aplicación y verifica que vuelve a aparecer un aviso alto.
        $this->travelTo($start->addDays(9));
        $this->command('POST', '/flocks/'.$flock->public_id.'/manejos/medication/'.$applicationId.'/correcciones', [
            'reason' => 'Registro de medicación incorrecto.',
        ])->assertCreated();
        $this->artisan('manejo:evaluar-sanidad')->assertSuccessful();
        $reopened = AppNotification::query()->where('type', 'medication_due')->whereNull('resolved_at')->sole();
        $this->assertSame(NotificationUrgency::High, $reopened->urgency);
        $this->assertNotSame($notice->id, $reopened->id);
        $this->assertNotNull($notice->refresh()->resolved_at);
    }
}
