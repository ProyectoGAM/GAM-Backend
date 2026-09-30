<?php

namespace Tests\Feature\ManagementPlans;

use App\Actions\ManagementPlans\AssignFlockPlanAction;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateVersion;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
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

    // Flujo: la consulta simple no revela borradores actuales ni descartados.
    public function test_view_only_user_cannot_read_unpublished_template_versions(): void
    {
        // Preparación: un gestor crea la primera versión en borrador.
        $this->signIn(['management-plans.manage']);
        $created = $this->command('POST', '/plantillas-manejo', $this->templatePayload())->assertCreated();
        $templateId = $created->json('data.id');
        $url = '/api/v1/plantillas-manejo/'.$templateId;
        $this->getJson($url)->assertOk()->assertJsonPath('data.version_status', 'draft');

        // Consulta: el lector no puede abrir la plantilla inédita, aunque conozca su ID.
        $this->signIn(['management-plans.view']);
        $this->getJson('/api/v1/plantillas-manejo')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'?version=1')->assertForbidden();

        // Mutación: se publica la primera versión y se crea una segunda en borrador.
        $this->signIn(['management-plans.manage']);
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/publicacion', ['expected_version' => 1])->assertOk();
        $this->command('PATCH', '/plantillas-manejo/'.$templateId, [
            'expected_version' => 1, 'activities' => $this->templatePayload('Pesaje pendiente')['activities'],
        ])->assertOk();

        // Consulta: el lector ve la publicada, pero no la revisión pendiente.
        $this->signIn(['management-plans.view']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.version_status', 'published');
        $this->getJson($url.'?version=2')->assertForbidden();
        $this->json('GET', $url, ['version' => 2])->assertForbidden();

        // Mutación: el gestor descarta el segundo borrador y publica la tercera versión.
        $this->signIn(['management-plans.manage']);
        $this->command('PATCH', '/plantillas-manejo/'.$templateId, [
            'expected_version' => 2, 'activities' => $this->templatePayload('Pesaje definitivo')['activities'],
        ])->assertOk();
        $this->getJson($url.'?version=2')->assertOk()->assertJsonPath('data.version_status', 'retired');
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/publicacion', ['expected_version' => 3])->assertOk();

        // Consulta: el lector conserva acceso a versiones publicadas, pero no al borrador descartado.
        $this->signIn(['management-plans.view']);
        $this->getJson($url.'?version=1')->assertOk()->assertJsonPath('data.version_status', 'retired');
        $this->getJson($url.'?version=2')->assertForbidden();
        $this->getJson($url)->assertOk()->assertJsonPath('data.version_status', 'published');
    }

    // Flujo: filtra borradores antes de paginar y reserva el filtro a gestores.
    public function test_draft_filter_combines_with_status_and_paginates_the_filtered_set(): void
    {
        // Preparación: crea una publicada, una inédita, una publicada con revisión y una retirada inédita.
        $this->signIn(['management-plans.manage']);
        $published = $this->command('POST', '/plantillas-manejo', [...$this->templatePayload(), 'name' => 'Publicada'])->assertCreated()->json('data.id');
        $this->command('POST', '/plantillas-manejo/'.$published.'/publicacion', ['expected_version' => 1])->assertOk();
        $draft = $this->command('POST', '/plantillas-manejo', [...$this->templatePayload(), 'name' => 'Inédita'])->assertCreated()->json('data.id');
        $revised = $this->command('POST', '/plantillas-manejo', [...$this->templatePayload(), 'name' => 'Revisada'])->assertCreated()->json('data.id');
        $this->command('POST', '/plantillas-manejo/'.$revised.'/publicacion', ['expected_version' => 1])->assertOk();
        $this->command('PATCH', '/plantillas-manejo/'.$revised, [
            'expected_version' => 1, 'activities' => $this->templatePayload('Revisión pendiente')['activities'],
        ])->assertOk();
        $retired = $this->command('POST', '/plantillas-manejo', [...$this->templatePayload(), 'name' => 'Retirada'])->assertCreated()->json('data.id');
        $this->command('POST', '/plantillas-manejo/'.$retired.'/retiro', ['expected_version' => 1])->assertOk();

        // Consulta: cada página y el filtro por estado usan el total de borradores.
        $ids = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson('/api/v1/plantillas-manejo?has_draft=1&per_page=1&page='.$page)->assertOk()
                ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)->assertJsonCount(1, 'data');
            $ids[] = $response->json('data.0.id');
        }
        $this->assertEqualsCanonicalizing([$draft, $revised, $retired], $ids);
        $this->getJson('/api/v1/plantillas-manejo?has_draft=1&status=retired&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('data.0.id', $retired);
        $this->getJson('/api/v1/plantillas-manejo?has_draft=0')->assertUnprocessable();

        // Consulta: un lector ve publicadas, pero no puede pedir el filtro ni por cuerpo JSON.
        $this->signIn(['management-plans.view']);
        $this->getJson('/api/v1/plantillas-manejo')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/plantillas-manejo?has_draft=1')->assertForbidden();
        $this->json('GET', '/api/v1/plantillas-manejo', ['has_draft' => 1])->assertForbidden();
    }

    // Flujo: reactiva una plantilla inédita sin publicarla y resuelve conflictos e idempotencia.
    public function test_activation_preserves_unpublished_template_and_requires_a_new_state(): void
    {
        // Preparación: crea y retira una plantilla aún no publicada.
        $this->signIn(['management-plans.manage']);
        $created = $this->command('POST', '/plantillas-manejo', $this->templatePayload())->assertCreated();
        $templateId = $created->json('data.id');
        $activityId = $created->json('data.activities.0.id');
        $path = '/plantillas-manejo/'.$templateId.'/activacion';
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/retiro', ['expected_version' => 1])->assertOk();

        // Request: una versión obsoleta falla y la versión actual reactiva una sola vez.
        $this->command('POST', $path, ['expected_version' => 2])->assertConflict();
        $key = (string) Str::uuid();
        $activated = $this->command('POST', $path, ['expected_version' => 1], $key)->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_version', 1)
            ->assertJsonPath('data.published_version', null)
            ->assertJsonPath('data.activities.0.id', $activityId);
        $replay = $this->command('POST', $path, ['expected_version' => 1], $key)->assertOk();
        $this->assertEquals($activated->json(), $replay->json());
        $this->command('POST', $path, ['expected_version' => 1])->assertConflict();

        // Request: una plantilla inédita reactivada sigue sin poder asignarse.
        $flock = $this->flock();
        $this->command('POST', '/flocks/'.$flock->public_id.'/plan-manejo', [
            'plan_template_id' => $templateId, 'plan_template_version' => 1,
        ])->assertConflict();

        // Consulta: persiste la misma versión y la auditoría se escribe una sola vez.
        $this->assertDatabaseHas('plan_templates', ['public_id' => $templateId, 'status' => 'active', 'current_version' => 1, 'published_version' => null]);
        $this->assertSame(1, PlanTemplateVersion::query()->count());
        $this->assertDatabaseCount('plan_template_activities', 1);
        $this->assertDatabaseCount('flock_plans', 0);
        $this->assertDatabaseHas('activity_log', ['event' => 'plan_template_activated', 'operation_id' => $activated->json('data.operation_id')]);
        $this->assertSame(1, Activity::query()->where('event', 'plan_template_activated')->count());
    }

    // Flujo: reactivar una plantilla con borrador conserva la publicación y su copia en el lote.
    public function test_activation_preserves_published_version_and_existing_flock_copy(): void
    {
        // Preparación: publica una plantilla, la asigna y la retira con una revisión pendiente.
        $actor = $this->signIn(['management-plans.manage']);
        $created = $this->command('POST', '/plantillas-manejo', $this->templatePayload())->assertCreated();
        $templateId = $created->json('data.id');
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/publicacion', ['expected_version' => 1])->assertOk();
        $flock = $this->flock();
        app(AssignFlockPlanAction::class)->assignPublished($flock, $templateId, 1, $actor, (string) Str::uuid());
        $copy = FlockPlanActivity::query()->firstOrFail();
        $this->command('PATCH', '/plantillas-manejo/'.$templateId, [
            'expected_version' => 1, 'activities' => $this->templatePayload('Pesaje pendiente')['activities'],
        ])->assertOk();
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/retiro', ['expected_version' => 2])->assertOk();

        // Request: el lector no reactiva; el gestor recupera la misma versión publicada.
        $this->signIn(['management-plans.view']);
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/activacion', ['expected_version' => 2])->assertForbidden();
        $this->signIn(['management-plans.manage']);
        $this->command('POST', '/plantillas-manejo/'.$templateId.'/activacion', ['expected_version' => 2])->assertOk()
            ->assertJsonPath('data.current_version', 2)
            ->assertJsonPath('data.published_version', 1)
            ->assertJsonPath('data.version_status', 'draft');

        // Consulta: la publicación y la actividad copiada permanecen intactas.
        $this->assertDatabaseHas('plan_template_versions', ['plan_template_id' => PlanTemplate::query()->where('public_id', $templateId)->value('id'), 'number' => 1, 'status' => 'published']);
        $this->assertDatabaseHas('plan_template_versions', ['plan_template_id' => PlanTemplate::query()->where('public_id', $templateId)->value('id'), 'number' => 2, 'status' => 'draft']);
        $this->assertDatabaseCount('plan_template_versions', 2);
        $this->assertDatabaseHas('flock_plan_activities', ['id' => $copy->id, 'title' => $copy->title]);
        $this->assertDatabaseCount('flock_plan_activities', 1);
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
