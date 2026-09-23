<?php

namespace Tests\Feature\ManagementPlans;

use App\Actions\ManagementPlans\AssignFlockPlanAction;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\PlanTemplate;
use Illuminate\Support\Str;
use Tests\Feature\Lots\LotsTestCase;

final class PlanTemplateEndpointTest extends LotsTestCase
{
    /** @return array<string, mixed> */
    private function templatePayload(string $title = 'Pesaje inicial'): array
    {
        return [
            'name' => 'Plan de ponedoras', 'description' => 'Plan configurable de prueba.',
            'activities' => [[
                'type' => 'weighing', 'title' => $title,
                'timing_kind' => 'day', 'start_day' => 1,
            ]],
        ];
    }

    // Flujo: publica dos versiones y conserva copias independientes en tres lotes.
    public function test_published_versions_do_not_mutate_existing_flock_copies(): void
    {
        // Preparación: autentica a un gestor y crea la primera versión.
        $actor = $this->signIn(['management-plans.view', 'management-plans.manage']);
        $created = $this->command('POST', '/plantillas-manejo', $this->templatePayload())->assertCreated();
        $templateId = $created->json('data.id');
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/publicacion', ['expected_version' => 1])->assertOk();

        // Mutación: asigna dos copias de la misma versión publicada.
        $first = $this->flock();
        $second = $this->flock();
        $assignment = app(AssignFlockPlanAction::class);
        $assignment->assignPublished($first, $templateId, 1, $actor, (string) Str::uuid());
        $assignment->assignPublished($second, $templateId, 1, $actor, (string) Str::uuid());
        $firstPlan = FlockPlan::query()->where('flock_id', $first->id)->firstOrFail();
        $secondPlan = FlockPlan::query()->where('flock_id', $second->id)->firstOrFail();
        $this->assertNotSame($firstPlan->id, $secondPlan->id);

        // Mutación: prepara y publica la segunda versión.
        $this->command('PATCH', '/plantillas-manejo/'.$templateId, [
            'expected_version' => 1, 'activities' => $this->templatePayload('Pesaje revisado')['activities'],
        ])->assertOk()->assertJsonPath('data.current_version', 2);
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/publicacion', ['expected_version' => 2])->assertOk();
        $third = $this->flock();
        $assignment->assignPublished($third, $templateId, 2, $actor, (string) Str::uuid());

        // Consulta: las dos copias anteriores conservan su contenido original.
        $firstActivity = FlockPlanActivity::query()->whereHas('revision', fn ($query) => $query->where('flock_plan_id', $firstPlan->id))->firstOrFail();
        $secondActivity = FlockPlanActivity::query()->whereHas('revision', fn ($query) => $query->where('flock_plan_id', $secondPlan->id))->firstOrFail();
        $thirdPlan = FlockPlan::query()->where('flock_id', $third->id)->firstOrFail();
        $thirdActivity = FlockPlanActivity::query()->whereHas('revision', fn ($query) => $query->where('flock_plan_id', $thirdPlan->id))->firstOrFail();
        $this->assertSame('Pesaje inicial', $firstActivity->title);
        $this->assertSame('Pesaje inicial', $secondActivity->title);
        $this->assertSame('Pesaje revisado', $thirdActivity->title);
        $this->assertDatabaseHas('activity_log', ['event' => 'plan_template_published']);
    }

    // Flujo: la revisión del lote conserva actividades previas y exige versión actual.
    public function test_flock_plan_revision_is_append_only_and_versioned(): void
    {
        // Preparación: crea un lote con plan y captura el identificador de actividad.
        $actor = $this->signIn(['management-plans.view', 'management-plans.manage']);
        $template = PlanTemplate::factory()->published()->create(['created_by' => $actor->id]);
        $flock = $this->flock();
        app(AssignFlockPlanAction::class)->assignPublished($flock, $template->public_id, 1, $actor, (string) Str::uuid());
        $original = FlockPlanActivity::query()->firstOrFail();

        // Request: reemplaza el plan vigente por una revisión completa.
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/plan-manejo', [
            'expected_revision' => 1, 'reason' => 'Ajuste de manejo.',
            'activities' => $this->templatePayload('Pesaje ajustado')['activities'],
        ])->assertOk()->assertJsonPath('data.current_revision', 2);
        $this->assertDatabaseHas('flock_plan_activities', ['id' => $original->id, 'title' => $original->title]);
        $this->assertDatabaseCount('flock_plan_revisions', 2);
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/plan-manejo', [
            'expected_revision' => 1, 'reason' => 'Ajuste obsoleto.',
            'activities' => $this->templatePayload()['activities'],
        ])->assertConflict();
    }

    // Flujo: un lote legado recibe un plan explícito y el reintento no lo duplica.
    public function test_legacy_flock_assignment_is_explicit_and_idempotent(): void
    {
        // Preparación: crea un lote anterior sin plan.
        $actor = $this->signIn(['management-plans.view', 'management-plans.manage']);
        $template = PlanTemplate::factory()->published()->create(['created_by' => $actor->id]);
        $flock = $this->flock();
        $key = (string) Str::uuid();
        $payload = ['plan_template_id' => $template->public_id, 'plan_template_version' => 1];

        // Request: asigna y repite la operación con la misma clave.
        $first = $this->command('POST', '/flocks/'.$flock->public_id.'/plan-manejo', $payload, $key)->assertCreated();
        $replay = $this->command('POST', '/flocks/'.$flock->public_id.'/plan-manejo', $payload, $key)->assertCreated();
        $this->assertEquals($first->json(), $replay->json());
        $this->assertDatabaseCount('flock_plans', 1);
        $this->assertDatabaseCount('flock_plan_revisions', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'flock_plan_assigned']);
    }

    // Flujo: limita la consulta y la escritura a permisos funcionales.
    public function test_template_routes_require_permissions(): void
    {
        // Preparación: crea una plantilla publicada y un usuario sin permisos.
        $template = PlanTemplate::factory()->published()->create();
        $this->signIn([]);

        // Request: rechaza ambas operaciones sin permiso.
        $this->getJson('/api/v1/plantillas-manejo')->assertForbidden();
        $this->command('POST', '/plantillas-manejo', $this->templatePayload())->assertForbidden();

        // Mutación: concede solo consulta y mantiene protegida la escritura.
        $this->signIn(['management-plans.view']);
        $this->getJson('/api/v1/plantillas-manejo/'.$template->public_id)->assertOk();
        $this->command('POST', '/plantillas-manejo', $this->templatePayload())->assertForbidden();
    }

    // Flujo: admite tramos paralelos, fin abierto y comienzo quincenal configurable.
    public function test_temporal_contract_keeps_parallel_week_nine_and_open_ranges(): void
    {
        // Preparación: configura actividades sin imponer un calendario clínico.
        $this->signIn(['management-plans.manage']);
        $activities = [
            ['type' => 'ration_change', 'title' => 'Recría', 'timing_kind' => 'week_range', 'start_week' => 6, 'end_week' => 9],
            ['type' => 'ration_change', 'title' => 'Desarrollo', 'timing_kind' => 'week_range', 'start_week' => 9, 'end_week' => 18],
            ['type' => 'ration_change', 'title' => 'Fase 2', 'timing_kind' => 'week_range', 'start_week' => 41],
            ['type' => 'weighing', 'title' => 'Pesaje quincenal', 'timing_kind' => 'day_recurrence', 'start_day' => 25, 'interval_days' => 15, 'end_week' => 16],
            ['type' => 'manual_practice', 'title' => 'Despique semana 12', 'timing_kind' => 'week', 'start_week' => 12, 'conditional' => true, 'condition' => 'Si amerita.'],
        ];

        // Request: crea la plantilla y comprueba que la semana 9 no se ajusta.
        $response = $this->command('POST', '/plantillas-manejo', [
            'name' => 'Plan temporal configurable', 'activities' => $activities,
        ])->assertCreated();
        $this->assertSame(9, $response->json('data.activities.0.end_week'));
        $this->assertSame(9, $response->json('data.activities.1.start_week'));
        $this->assertNull($response->json('data.activities.2.end_week'));
        $this->assertSame(25, $response->json('data.activities.3.start_day'));
        $this->assertTrue($response->json('data.activities.4.conditional'));

        // Request: rechaza una recurrencia con dos finales contradictorios.
        $this->command('POST', '/plantillas-manejo', [
            'name' => 'Recurrencia ambigua',
            'activities' => [[
                'type' => 'weighing', 'title' => 'Pesaje ambiguo',
                'timing_kind' => 'day_recurrence', 'start_day' => 23,
                'interval_days' => 15, 'end_day' => 100, 'end_week' => 16,
            ]],
        ])->assertConflict();
    }

    // Flujo: revisar el plan conserva el vínculo histórico de una práctica realizada.
    public function test_revision_preserves_prior_execution_link_and_activity(): void
    {
        // Preparación: asigna una práctica prevista y registra su ejecución explícita.
        $this->signIn(['management-plans.view', 'management-plans.manage', 'management-plans.execute']);
        $flock = $this->flockWithPlan(100, activities: [[
            'type' => 'manual_practice', 'title' => 'Despique previsto', 'timing_kind' => 'week', 'start_week' => 12,
        ]]);
        $activity = FlockPlanActivity::query()->firstOrFail();
        $execution = $this->command('POST', '/flocks/'.$flock->public_id.'/practicas', [
            'practice_type' => 'beak_trimming',
            'plan_activity_id' => $activity->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'notes' => 'Práctica realizada.',
        ])->assertCreated();

        // Request: cambia la revisión vigente y consulta todas las revisiones.
        $this->command('PATCH', '/flocks/'.$flock->public_id.'/plan-manejo', [
            'expected_revision' => 1,
            'reason' => 'Se actualizó el manejo previsto.',
            'activities' => $this->templatePayload('Pesaje posterior')['activities'],
        ])->assertOk();
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/plan-manejo?include_revisions=1')->assertOk()
            ->assertJsonCount(2, 'data.revisions')
            ->assertJsonPath('data.revisions.0.activities.0.id', $activity->public_id);
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos?type=manual_practice')->assertOk()
            ->assertJsonPath('data.0.operation_id', $execution->json('data.operation_id'))
            ->assertJsonPath('data.0.plan_activity_id', $activity->public_id);
    }
}
