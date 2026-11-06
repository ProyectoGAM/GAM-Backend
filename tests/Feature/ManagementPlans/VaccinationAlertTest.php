<?php

namespace Tests\Feature\ManagementPlans;

use App\Enums\Notifications\NotificationUrgency;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\Notifications\AppNotification;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Tests\Feature\Lots\LotsTestCase;

final class VaccinationAlertTest extends LotsTestCase
{
    // Flujo: avisa a siete días, escala el aviso al vencimiento y conserva el historial de resolución.
    public function test_pending_vaccination_escalates_and_is_resolved_by_its_application(): void
    {
        // Preparación: fija la fecha local y crea un plan con una vacuna que vence en ocho días.
        $start = now(config('lots.timezone'))->startOfDay()->addHours(12)->toImmutable();
        $this->travelTo($start);
        $recipient = $this->signIn(['can_view_management_plans', 'can_manage_management_plans', 'can_execute_management_plans', 'can_view_notifications']);
        $vaccine = Vaccine::factory()->create();
        $flock = $this->flockWithPlan(activities: [[
            'type' => 'vaccination', 'title' => 'Vacuna de prueba', 'timing_kind' => 'day',
            'start_day' => 39, 'start_week' => null, 'catalog_type' => 'vaccine', 'catalog_id' => $vaccine->id,
            'catalog_snapshot' => ['id' => $vaccine->public_id],
        ]]);
        $activity = FlockPlanActivity::query()->where('type', 'vaccination')->sole();

        // Consulta: el umbral de una semana es inclusivo y repetir la evaluación mantiene un solo aviso.
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo($start->addDay());
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame($recipient->id, $notice->recipient_id);
        $this->assertSame(NotificationUrgency::Medium, $notice->urgency);
        $this->assertSame($activity->public_id, $notice->entity_id);

        // Mutación: lee el aviso y avanza hasta el día previsto.
        $url = '/api/v1/notificaciones/'.$notice->public_id;
        $this->patchJson($url.'/lectura')->assertOk();
        $this->travelTo($start->addDays(8));
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.leida_en', null)->assertJsonPath('data.estado_resolucion', 'no_resuelto')
            ->assertJsonPath('data.modulo', 'management_plans')->assertJsonPath('data.tipo', 'vaccination_due');
        $this->assertDatabaseCount('app_notifications', 1);

        // Request: una aplicación fuera del plan no satisface la dosis programada.
        $applicationUrl = '/flocks/'.$flock->public_id.'/vacunaciones';
        $this->command('POST', $applicationUrl, [
            'vaccine_id' => $vaccine->public_id, 'reason' => 'Aplicación fuera del plan.',
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated();
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->assertNull($notice->refresh()->resolved_at);

        // Request: registra la vacuna vinculada a la dosis y verifica que el aviso se resuelve.
        $applicationId = $this->command('POST', $applicationUrl, [
            'vaccine_id' => $vaccine->public_id, 'plan_activity_id' => $activity->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated()->json('data.vaccination_application.id');
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.estado_resolucion', 'resuelto');

        // Request: una revisión que conserva la dosis reconoce la aplicación previa.
        $feeding = FlockPlanActivity::query()->where('type', 'ration_change')->sole();
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/plan-manejo', [
            'expected_revision' => 1,
            'reason' => 'Se aclaró el nombre de la actividad.',
            'activities' => [
                [
                    'type' => 'vaccination', 'title' => 'Vacuna de prueba actualizada', 'timing_kind' => 'day',
                    'start_day' => 39, 'catalog_ref' => $vaccine->public_id,
                ],
                [
                    'type' => 'ration_change', 'title' => $feeding->title, 'timing_kind' => $feeding->timing_kind,
                    'start_day' => $feeding->start_day, 'start_week' => $feeding->start_week, 'end_week' => $feeding->end_week,
                    'catalog_ref' => (string) $feeding->feed_recipe_id, 'grams_per_bird' => $feeding->grams_per_bird,
                ],
            ],
        ])->assertOk();
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 1);
        $revisedActivity = FlockPlanActivity::query()->where('title', 'Vacuna de prueba actualizada')->sole();

        // Request: anula la aplicación al día siguiente y comprueba que vuelve a aparecer una alerta alta.
        $this->travelTo($start->addDays(9));
        $this->command('POST', '/flocks/'.$flock->public_id.'/manejos/vaccination/'.$applicationId.'/correcciones', [
            'reason' => 'Registro de aplicación incorrecto.',
        ])->assertCreated();
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 2);
        $reopened = AppNotification::query()->whereNull('resolved_at')->sole();
        $this->assertSame(NotificationUrgency::High, $reopened->urgency);
        $this->assertSame($revisedActivity->public_id, $reopened->entity_id);
        $this->assertNotNull($notice->refresh()->resolved_at);
    }

    // Flujo: una sola aplicación vinculada cubre una sola fecha de una pauta recurrente.
    public function test_recurring_doses_are_evaluated_separately(): void
    {
        // Preparación: programa dos fechas, una vencida y otra dentro de siete días.
        $this->signIn(['can_view_management_plans', 'can_execute_management_plans', 'can_view_notifications']);
        $vaccine = Vaccine::factory()->create();
        $flock = $this->flockWithPlan(activities: [[
            'type' => 'vaccination', 'title' => 'Vacunación recurrente', 'timing_kind' => 'day_recurrence',
            'start_day' => 30, 'start_week' => null, 'end_day' => 38, 'interval_days' => 8,
        ]]);
        $activity = FlockPlanActivity::query()->where('type', 'vaccination')->sole();

        // Consulta: cada fecha pendiente genera su propio aviso con la urgencia correspondiente.
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 2);
        $overdue = AppNotification::query()->where('urgency', NotificationUrgency::High)->sole();
        $upcoming = AppNotification::query()->where('urgency', NotificationUrgency::Medium)->sole();

        // Request: aplica una dosis, que se imputa a la fecha pendiente más antigua.
        $this->command('POST', '/flocks/'.$flock->public_id.'/vacunaciones', [
            'vaccine_id' => $vaccine->public_id, 'plan_activity_id' => $activity->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated();
        $this->artisan('manejo:evaluar-vacunaciones')->assertSuccessful();
        $this->assertNotNull($overdue->refresh()->resolved_at);
        $this->assertNull($upcoming->refresh()->resolved_at);
    }
}
