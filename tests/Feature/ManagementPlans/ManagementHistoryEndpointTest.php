<?php

namespace Tests\Feature\ManagementPlans;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\SuppliersAndCatalogs\Medicine;
use Tests\Feature\Lots\LotsTestCase;

final class ManagementHistoryEndpointTest extends LotsTestCase
{
    // Flujo: el fraccionamiento sólo copia actividades puntuales pendientes.
    public function test_partial_split_excludes_completed_activity_from_child_plan(): void
    {
        // Preparación: ejecuta una práctica prevista y deja un pesaje para la semana siguiente.
        $this->signIn(['flocks.redistribute', 'management-plans.view', 'management-plans.execute']);
        $source = $this->flockWithPlan(100, activities: [
            ['type' => 'manual_practice', 'title' => 'Colocar nidos', 'timing_kind' => 'week', 'start_week' => 5],
            ['type' => 'weighing', 'title' => 'Pesaje siguiente', 'timing_kind' => 'week', 'start_week' => 6],
            ['type' => 'weighing', 'title' => 'Pesaje agotado', 'timing_kind' => 'day_recurrence', 'start_day' => 2, 'end_day' => 31, 'interval_days' => 15],
        ]);
        $practice = FlockPlanActivity::query()->where('title', 'Colocar nidos')->firstOrFail();
        $this->command('POST', '/flocks/'.$source->public_id.'/practicas', [
            'practice_type' => 'nest_placement', 'plan_activity_id' => $practice->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'notes' => 'Se colocaron los nidos previstos.',
        ])->assertCreated();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 50]);

        // Request: crea el lote hijo y consulta su copia del plan.
        $split = $this->command('POST', '/flocks/'.$source->public_id.'/redistributions', [
            'version' => 1, 'quantity' => 20,
            'destination_poultry_house_id' => $house->id,
            'destination_code' => 'PEND-20',
        ])->assertCreated();
        $childId = $split->json('data.destination_flock.id');
        $plan = $this->getJson('/api/v1/flocks/'.$childId.'/plan-manejo')->assertOk();

        // Verificación: conserva el pesaje futuro y expone la práctica como antecedente.
        $plan->assertJsonCount(1, 'data.revisions.0.activities')
            ->assertJsonPath('data.revisions.0.activities.0.title', 'Pesaje siguiente');
        $this->getJson('/api/v1/flocks/'.$childId.'/manejos?type=manual_practice')->assertOk()
            ->assertJsonPath('data.0.plan_activity_id', $practice->public_id)
            ->assertJsonPath('data.0.provenance.kind', 'antecedent');
    }

    // Flujo: el lote fraccionado muestra antecedentes de origen una sola vez y admite paginación.
    public function test_partial_split_exposes_antecedents_with_provenance_and_pagination(): void
    {
        // Preparación: registra dos tratamientos antes del fraccionamiento.
        $this->signIn(['flocks.redistribute', 'management-plans.view', 'management-plans.execute']);
        $source = $this->flockWithPlan();
        $medicine = Medicine::factory()->create();
        $first = $this->command('POST', '/flocks/'.$source->public_id.'/medicaciones', [
            'medicine_id' => $medicine->public_id, 'quantity' => 1, 'reason' => 'Primer manejo',
            'occurred_at' => now()->subMinutes(3)->toIso8601String(),
        ])->assertCreated();
        $second = $this->command('POST', '/flocks/'.$source->public_id.'/medicaciones', [
            'medicine_id' => $medicine->public_id, 'quantity' => 1, 'reason' => 'Segundo manejo',
            'occurred_at' => now()->subMinutes(2)->toIso8601String(),
        ])->assertCreated();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 50]);

        // Request: crea lote hijo con copia independiente del plan.
        $split = $this->command('POST', '/flocks/'.$source->public_id.'/redistributions', [
            'version' => 1, 'quantity' => 20,
            'destination_poultry_house_id' => $house->id,
            'destination_code' => 'HIST-20',
        ])->assertCreated();
        $childId = $split->json('data.destination_flock.id');

        // Consulta: pagina los dos antecedentes sin crear aplicaciones duplicadas.
        $pageOne = $this->getJson('/api/v1/flocks/'.$childId.'/manejos?type=medication&per_page=1&page=1')->assertOk();
        $pageTwo = $this->getJson('/api/v1/flocks/'.$childId.'/manejos?type=medication&per_page=1&page=2')->assertOk();
        $this->assertSame(2, $pageOne->json('meta.total'));
        $this->assertSame(1, $pageOne->json('meta.per_page'));
        $this->assertSame('antecedent', $pageOne->json('data.0.provenance.kind'));
        $this->assertSame($source->public_id, $pageOne->json('data.0.provenance.flock_id'));
        $this->assertEqualsCanonicalizing(
            [$first->json('data.operation_id'), $second->json('data.operation_id')],
            [$pageOne->json('data.0.operation_id'), $pageTwo->json('data.0.operation_id')],
        );
        $this->assertDatabaseCount('medicine_applications', 2);
    }

    // Flujo: el historial exige permiso y limita los filtros de consulta.
    public function test_history_requires_view_permission_and_bounded_filters(): void
    {
        // Preparación: genera un lote y luego cambia a un actor sin permiso.
        $this->signIn(['management-plans.view']);
        $flock = $this->flockWithPlan();
        $this->signIn([]);

        // Request: rechaza la consulta sin permiso.
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos')->assertForbidden();

        // Request: incluso con permiso valida paginación y filtros.
        $this->signIn(['management-plans.view']);
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos?unknown=yes')->assertUnprocessable();
    }
}
