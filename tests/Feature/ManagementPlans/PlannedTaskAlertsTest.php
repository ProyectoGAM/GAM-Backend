<?php

namespace Tests\Feature\ManagementPlans;

use App\Enums\Notifications\NotificationUrgency;
use App\Models\Lots\EggCollection;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityRecord;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\Notifications\AppNotification;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Lots\LotsTestCase;

final class PlannedTaskAlertsTest extends LotsTestCase
{
    // Flujo: un despique escala el día previsto y exige permisos separados para registrar e invalidar.
    public function test_beak_trimming_alert_and_history_use_distinct_permissions(): void
    {
        // Preparación: programa el despique a cuatro días y distingue al operador del usuario que registra.
        $start = now(config('lots.timezone'))->startOfDay()->addHours(12)->toImmutable();
        $this->travelTo($start);
        $actor = $this->signIn(['can_view_management_plans', 'can_execute_management_plans', 'can_view_notifications']);
        $responsible = User::factory()->create();
        $flock = $this->flockWithPlan(activities: [[
            'type' => 'manual_practice', 'practice_type' => 'beak_trimming', 'title' => 'Despique programado',
            'timing_kind' => 'day', 'start_day' => 35, 'start_week' => null,
        ]]);
        $activity = FlockPlanActivity::query()->where('practice_type', 'beak_trimming')->sole();
        $path = '/flocks/'.$flock->public_id;
        $data = [
            'practice_type' => 'beak_trimming', 'plan_activity_id' => $activity->public_id,
            'occurred_at' => $start->toIso8601String(), 'responsible_user_id' => $responsible->id,
        ];

        // Request: el permiso general no permite registrar un despique.
        $this->command('POST', $path.'/practicas', $data)->assertForbidden();
        $this->assertDatabaseCount('manual_practices', 0);
        $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_record_beak_trimming', 'web'));

        // Consulta: aparece un único aviso medio a tres días y vuelve a quedar sin leer al vencer.
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo($start->addDay());
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame(NotificationUrgency::Medium, $notice->urgency);
        $noticeUrl = '/api/v1/notificaciones/'.$notice->public_id;
        $this->patchJson($noticeUrl.'/lectura')->assertOk();
        $this->travelTo($start->addDays(4));
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->getJson($noticeUrl)->assertOk()->assertJsonPath('data.urgencia', 'alto')
            ->assertJsonPath('data.tipo', 'beak_trimming_due')->assertJsonPath('data.leida_en', null);
        $this->assertDatabaseCount('app_notifications', 1);

        // Request: una práctica de otro tipo no acredita el despique, que admite detalles omitidos.
        $this->command('POST', $path.'/practicas', [...$data, 'practice_type' => 'nest_placement', 'notes' => 'Otro manejo.'])->assertConflict();
        $id = $this->command('POST', $path.'/practicas', $data)->assertCreated()
            ->assertJsonPath('data.manual_practice.notes', null)
            ->assertJsonPath('data.manual_practice.responsible.id', $responsible->id)
            ->json('data.manual_practice.id');
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertNotNull($notice->refresh()->resolved_at);

        // Request: invalidar exige otro permiso y preserva ambas entradas en el historial.
        $correction = $path.'/manejos/manual_practice/'.$id.'/correcciones';
        $this->command('POST', $correction, ['reason' => 'Se registró en el lote equivocado.'])->assertForbidden();
        $this->assertDatabaseCount('management_execution_corrections', 0);
        $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_reverse_beak_trimming', 'web'));
        $this->command('POST', $correction, ['reason' => 'Se registró en el lote equivocado.'])->assertCreated();
        $history = collect($this->getJson('/api/v1'.$path.'/manejos')->assertOk()->json('data'));
        $this->assertSame($responsible->id, $history->firstWhere('id', $id)['responsible']['user_id']);
        $this->assertSame($id, $history->firstWhere('type', 'management_correction')['module_data']['original_id']);
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 2);
        $this->assertSame(NotificationUrgency::High, AppNotification::query()->whereNull('resolved_at')->sole()->urgency);
    }

    // Flujo: una invalidación anticipada reabre su ocurrencia y una nueva ejecución la cubre.
    public function test_early_invalidation_does_not_escalate_the_next_recurrence(): void
    {
        // Preparación: programa dos ocurrencias de despique fuera de la ventana de aviso.
        $start = now(config('lots.timezone'))->startOfDay()->addHours(12)->toImmutable();
        $this->travelTo($start);
        $this->signIn(['can_view_management_plans', 'can_execute_management_plans', 'can_view_notifications',
            'can_record_beak_trimming', 'can_reverse_beak_trimming']);
        $flock = $this->flockWithPlan(activities: [[
            'type' => 'manual_practice', 'practice_type' => 'beak_trimming', 'title' => 'Despique recurrente',
            'timing_kind' => 'day_recurrence', 'start_day' => 37, 'start_week' => null, 'end_day' => 45, 'interval_days' => 8,
        ]]);
        $activity = FlockPlanActivity::query()->where('practice_type', 'beak_trimming')->sole();
        $path = '/flocks/'.$flock->public_id;
        $data = [
            'practice_type' => 'beak_trimming', 'plan_activity_id' => $activity->public_id, 'occurred_at' => $start->toIso8601String(),
        ];

        // Request: invalida el registro antes del umbral de tres días y comprueba que el aviso es alto.
        $id = $this->command('POST', $path.'/practicas', $data)->assertCreated()->json('data.manual_practice.id');
        $this->command('POST', $path.'/manejos/manual_practice/'.$id.'/correcciones', ['reason' => 'Carga por error.'])->assertCreated();
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame(NotificationUrgency::High, $notice->urgency);

        // Request: registra la ejecución válida y verifica que la segunda ocurrencia conserva su fecha.
        $this->command('POST', $path.'/practicas', $data)->assertCreated();
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertNotNull($notice->refresh()->resolved_at);
        $this->travelTo($start->addDays(11));
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertSame(NotificationUrgency::Medium, AppNotification::query()->whereNull('resolved_at')->sole()->urgency);
    }

    // Flujo: las tareas pendientes generan alertas altas desde el día previsto y se acreditan con el registro válido de cada módulo.
    public function test_only_eligible_unfulfilled_tasks_alert_from_the_planned_day(): void
    {
        // Preparación: fija tareas que vencen mañana y otras que están fuera del alcance.
        $start = now(config('lots.timezone'))->startOfDay()->addHours(12)->toImmutable();
        $this->travelTo($start);
        $actor = $this->signIn(['can_view_management_plans', 'can_execute_management_plans', 'can_view_notifications']);
        $day = ['timing_kind' => 'day', 'start_day' => 32, 'start_week' => null];
        $flock = $this->flockWithPlan(activities: [
            [...$day, 'type' => 'manual_practice', 'practice_type' => 'nest_placement', 'title' => 'Colocar nidos'],
            [...$day, 'type' => 'flock_movement', 'title' => 'Traslado previsto'],
            [...$day, 'type' => 'egg_collection', 'title' => 'Recolección prevista'],
            [...$day, 'type' => 'mortality', 'title' => 'Control previsto'],
            [...$day, 'type' => 'weighing', 'title' => 'Pesaje excluido'],
            [...$day, 'type' => 'manual_practice', 'practice_type' => 'beak_trimming', 'title' => 'Despique condicional',
                'conditional' => true, 'condition' => 'Si corresponde.'],
            ['type' => 'manual_practice', 'title' => 'Práctica sin fecha', 'timing_kind' => 'unscheduled', 'start_week' => null],
        ]);
        $activities = FlockPlanActivity::query()->get()->keyBy('type');

        // Consulta: la víspera no hay avisos y el día previsto todas las alertas son altas.
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->travelTo($start->addDay());
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertSame(['egg_collection_due', 'flock_movement_due', 'manual_practice_due', 'mortality_due'],
            AppNotification::query()->where('urgency', NotificationUrgency::High)->orderBy('type')->pluck('type')->all());
        $this->assertDatabaseCount('app_notifications', 4);

        // Consulta: las alertas siguen vigentes al día siguiente, sin duplicados.
        $this->travelTo($start->addDays(2));
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertDatabaseCount('app_notifications', 4);
        $this->assertSame(4, AppNotification::query()->where('urgency', NotificationUrgency::High)->whereNull('resolved_at')->count());

        // Mutación: registra las cuatro ejecuciones, con vínculo al plan del lote.
        $nests = FlockPlanActivity::query()->where('practice_type', 'nest_placement')->sole();
        $this->command('POST', '/flocks/'.$flock->public_id.'/practicas', [
            'practice_type' => 'nest_placement', 'plan_activity_id' => $nests->public_id, 'notes' => 'Nidos colocados.',
            'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();
        $collection = EggCollection::factory()->create([
            'flock_id' => $flock->id, 'flock_plan_activity_id' => $activities['egg_collection']->id, 'created_by' => $actor->id,
        ]);
        MortalityRecord::factory()->create([
            'flock_id' => $flock->id, 'flock_plan_activity_id' => $activities['mortality']->id, 'created_by' => $actor->id,
        ]);
        $movement = FlockMovement::factory()->create([
            'type' => 'total', 'source_flock_id' => $flock->id, 'destination_flock_id' => $flock->id,
            'flock_plan_activity_id' => $activities['flock_movement']->id, 'created_by' => $actor->id,
        ]);
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertSame(0, AppNotification::query()->whereNull('resolved_at')->count());

        // Mutación: una recolección cancelada y un movimiento revertido dejan de acreditar su tarea.
        $collection->forceFill(['status' => 'cancelled'])->save();
        FlockMovement::factory()->create([
            'type' => 'redistribution_reversal', 'source_flock_id' => $flock->id, 'destination_flock_id' => $flock->id,
            'reverses_movement_id' => $movement->id, 'created_by' => $actor->id,
        ]);
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $this->assertSame(['egg_collection_due', 'flock_movement_due'],
            AppNotification::query()->whereNull('resolved_at')->orderBy('type')->pluck('type')->all());
    }

    // Flujo: una revisión identifica explícitamente el despique de una práctica histórica.
    public function test_plan_revision_persists_the_manual_practice_type(): void
    {
        // Preparación: crea un plan previo con el subtipo vacío.
        $this->signIn(['can_view_management_plans', 'can_manage_management_plans', 'can_view_notifications']);
        $flock = $this->flockWithPlan(activities: [[
            'type' => 'manual_practice', 'title' => 'Despique previsto', 'timing_kind' => 'day', 'start_day' => 32, 'start_week' => null,
        ]]);
        $feeding = FlockPlanActivity::query()->where('type', 'ration_change')->sole();
        $path = '/flocks/'.$flock->public_id.'/plan-manejo';

        // Request: publica la revisión tipada y consulta la representación del plan.
        $this->command('PATCH', $path, [
            'expected_revision' => 1, 'reason' => 'Se tipificó la práctica.',
            'activities' => [
                [
                    'type' => 'manual_practice', 'practice_type' => 'beak_trimming',
                    'title' => 'Despique previsto', 'timing_kind' => 'day', 'start_day' => 32,
                ],
                [
                    'type' => 'ration_change', 'title' => $feeding->title, 'timing_kind' => $feeding->timing_kind,
                    'start_day' => $feeding->start_day, 'start_week' => $feeding->start_week, 'end_week' => $feeding->end_week,
                    'catalog_ref' => (string) $feeding->feed_recipe_id, 'grams_per_bird' => $feeding->grams_per_bird,
                ],
            ],
        ])->assertOk()->assertJsonPath('data.revisions.0.activities.0.practice_type', 'beak_trimming');
        $this->getJson('/api/v1'.$path.'?include_revisions=1')->assertOk()
            ->assertJsonPath('data.revisions.0.activities.0.practice_type', null)
            ->assertJsonPath('data.revisions.1.activities.0.practice_type', 'beak_trimming');

        // Consulta: el aviso se vincula a la actividad de la revisión nueva.
        $activity = FlockPlanActivity::query()->where('practice_type', 'beak_trimming')->sole();
        $this->artisan('manejo:evaluar-tareas')->assertSuccessful();
        $notice = AppNotification::query()->sole();
        $this->assertSame('beak_trimming_due', $notice->type);
        $this->assertSame($activity->public_id, $notice->entity_id);
        $this->assertSame('/administracion/manejo-lotes/planes/lotes/'.$flock->public_id, $notice->target_path);
    }
}
