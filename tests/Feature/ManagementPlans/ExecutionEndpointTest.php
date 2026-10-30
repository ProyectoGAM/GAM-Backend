<?php

namespace Tests\Feature\ManagementPlans;

use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Vaccine;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\Lots\LotsTestCase;

final class ExecutionEndpointTest extends LotsTestCase
{
    // Flujo: registra una vacuna prevista y una medicación fuera de plan sin cantidad ni control de stock.
    public function test_planned_vaccination_and_unplanned_medication_remain_independent(): void
    {
        // Preparación: crea el lote con vacuna prevista y referencias reales de catálogo.
        $this->signIn(['management-plans.view', 'management-plans.execute']);
        $flock = $this->flockWithPlan(100, activities: [[
            'type' => 'vaccination', 'title' => 'Gumboro inicial', 'timing_kind' => 'week', 'start_week' => 1,
        ]]);
        $activity = FlockPlanActivity::query()->firstOrFail();
        $vaccine = Vaccine::factory()->create();
        $medicine = Medicine::factory()->create();
        $occurredAt = now()->subMinute()->toIso8601String();

        // Request: vincula solamente la vacunación y registra el medicamento sin cantidad.
        $vaccination = $this->command('POST', '/flocks/'.$flock->public_id.'/vacunaciones', [
            'vaccine_id' => $vaccine->public_id,
            'plan_activity_id' => $activity->public_id,
            'occurred_at' => $occurredAt,
        ])->assertCreated()->assertJsonPath('data.vaccination_application.plan_activity_id', $activity->public_id);
        $medication = $this->command('POST', '/flocks/'.$flock->public_id.'/medicaciones', [
            'medicine_id' => $medicine->public_id,
            'reason' => 'Tratamiento indicado',
            'notes' => 'Detalle libre de dosis.',
            'occurred_at' => $occurredAt,
        ])->assertCreated()->assertJsonPath('data.medicine_application.plan_activity_id', null)
            ->assertJsonMissingPath('data.stock_balance')
            ->assertJsonPath('data.warnings', []);

        // Mutación: cambia las fichas de catálogo después de las aplicaciones.
        $medicineName = $medicine->name;
        $vaccineDescription = $vaccine->description;
        $medicine->forceFill(['name' => 'Medicamento revisado'])->save();
        $vaccine->forceFill(['description' => 'Descripción revisada'])->save();
        $vaccine->product->forceFill(['status' => ProductStatus::Inactive])->save();

        // Consulta: el historial mantiene procedencia, operación y enlace explícito.
        $entries = collect($this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos?per_page=100')->assertOk()->json('data'));
        $planned = $entries->firstWhere('operation_id', $vaccination->json('data.operation_id'));
        $unplanned = $entries->firstWhere('operation_id', $medication->json('data.operation_id'));
        $this->assertNotNull($planned);
        $this->assertNotNull($unplanned);
        $this->assertSame($activity->public_id, $planned['plan_activity_id']);
        $this->assertFalse($planned['outside_plan']);
        $this->assertNull($unplanned['plan_activity_id']);
        $this->assertTrue($unplanned['outside_plan']);
        $this->assertSame('Detalle libre de dosis.', $unplanned['module_data']['notes']);
        $this->assertSame($medicineName, $unplanned['module_data']['medicine']['name']);
        $this->assertSame($vaccineDescription, $planned['module_data']['vaccine']['description']);
        $this->assertDatabaseCount('medicine_stock_movements', 0);
    }

    // Flujo: una corrección conserva el registro original y su motivo sin generar stock.
    #[TestWith(['medicine', 'medication', 'medicaciones'])]
    #[TestWith(['vaccine', 'vaccination', 'vacunaciones'])]
    public function test_application_correction_is_append_only_without_stock(string $kind, string $type, string $path): void
    {
        // Preparación: registra la aplicación efectiva.
        $this->signIn(['management-plans.view', 'management-plans.execute']);
        $flock = $this->flockWithPlan();
        $catalog = $kind === 'medicine' ? Medicine::factory()->create() : Vaccine::factory()->create();
        $root = $kind === 'medicine' ? 'medicine_application' : 'vaccination_application';
        $created = $this->command('POST', '/flocks/'.$flock->public_id.'/'.$path, [
            $kind.'_id' => $catalog->public_id,
            'reason' => 'Error de carga',
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated();
        $applicationId = $created->json('data.'.$root.'.id');

        // Request: registra la corrección y conserva la aplicación anterior.
        $this->command('POST', '/flocks/'.$flock->public_id.'/manejos/'.$type.'/'.$applicationId.'/correcciones', [
            'reason' => 'Aplicación registrada por error.',
        ])->assertCreated()->assertJsonPath('data.management_execution_correction.original_public_id', $applicationId)
            ->assertJsonPath('data.management_execution_correction.original_snapshot.reason', 'Error de carga')
            ->assertJsonPath('data.management_execution_correction.compensation', null);
        $this->assertDatabaseHas($root.'s', ['public_id' => $applicationId, 'reason' => 'Error de carga']);
        $this->assertDatabaseCount('medicine_stock_balances', 0);
        $this->assertDatabaseCount('medicine_stock_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('management_execution_corrections', 1);

        // Consulta: ambos hechos operativos figuran en la cronología.
        $entries = collect($this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos')->assertOk()->json('data'));
        $this->assertNotNull($entries->firstWhere('id', $applicationId));
        $this->assertNotNull($entries->firstWhere('type', 'management_correction'));
    }

    // Flujo: retira los ajustes de saldo y mantiene consultable el saldo histórico.
    public function test_medicine_stock_adjustment_is_retired_and_history_remains_readable(): void
    {
        // Preparación: concede gestión de stock y conserva un saldo previo a la retirada.
        $this->signIn(['management-plans.stock.manage']);
        $medicine = Medicine::factory()->create();
        MedicineStockBalance::factory()->create(['medicine_id' => $medicine->id, 'on_hand_quantity' => -1]);

        // Request: no permite un ajuste nuevo ni modifica el saldo existente.
        $this->command('POST', '/medicines/'.$medicine->public_id.'/ajustes-stock', [
            'quantity_delta' => 2, 'reason' => 'Conteo físico',
        ])->assertConflict()->assertJsonPath('detail', 'El control de stock de medicamentos está retirado. Registra las aplicaciones sin cantidad ni ajustes de saldo.');
        $this->assertDatabaseCount('medicine_stock_movements', 0);
        $this->assertDatabaseHas('medicine_stock_balances', ['medicine_id' => $medicine->id, 'on_hand_quantity' => -1]);

        // Consulta: permite leer la existencia histórica sin crear movimientos.
        $this->getJson('/api/v1/medicines/'.$medicine->public_id.'/stock')->assertOk()->assertJsonPath('data.quantity', -1);
    }
}
