<?php

namespace Tests\Feature\ManagementPlans;

use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Support\Str;
use Tests\Feature\Lots\LotsTestCase;

final class ExecutionEndpointTest extends LotsTestCase
{
    // Flujo: registra una vacuna prevista y una medicación fuera de plan con saldo negativo visible.
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

        // Request: vincula solamente la vacunación y descuenta dos unidades de medicamento.
        $vaccination = $this->command('POST', '/flocks/'.$flock->public_id.'/vacunaciones', [
            'vaccine_id' => $vaccine->public_id,
            'plan_activity_id' => $activity->public_id,
            'occurred_at' => $occurredAt,
        ])->assertCreated()->assertJsonPath('data.vaccination_application.plan_activity_id', $activity->public_id);
        $medication = $this->command('POST', '/flocks/'.$flock->public_id.'/medicaciones', [
            'medicine_id' => $medicine->public_id,
            'quantity' => 2,
            'reason' => 'Tratamiento indicado',
            'notes' => 'Detalle libre de dosis.',
            'occurred_at' => $occurredAt,
        ])->assertCreated()->assertJsonPath('data.medicine_application.plan_activity_id', null)
            ->assertJsonPath('data.stock_balance.quantity', -2)
            ->assertJsonPath('data.warnings.0.code', 'MEDICINE_STOCK_NEGATIVE');

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
        $this->assertDatabaseCount('medicine_stock_movements', 1);
    }

    // Flujo: una corrección conserva el registro original y compensa el conteo de medicamento.
    public function test_medication_correction_is_append_only_and_restores_stock(): void
    {
        // Preparación: registra la aplicación efectiva.
        $this->signIn(['management-plans.view', 'management-plans.execute']);
        $flock = $this->flockWithPlan();
        $medicine = Medicine::factory()->create();
        $created = $this->command('POST', '/flocks/'.$flock->public_id.'/medicaciones', [
            'medicine_id' => $medicine->public_id,
            'quantity' => 3,
            'reason' => 'Error de carga',
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated();
        $applicationId = $created->json('data.medicine_application.id');

        // Request: registra la corrección y conserva la aplicación anterior.
        $this->command('POST', '/flocks/'.$flock->public_id.'/manejos/medication/'.$applicationId.'/correcciones', [
            'reason' => 'Cantidad registrada por error.',
        ])->assertCreated()->assertJsonPath('data.management_execution_correction.original_public_id', $applicationId);
        $this->assertDatabaseHas('medicine_applications', ['public_id' => $applicationId, 'quantity' => 3]);
        $this->assertDatabaseHas('medicine_stock_balances', ['medicine_id' => $medicine->id, 'on_hand_quantity' => 0]);
        $this->assertDatabaseCount('medicine_stock_movements', 2);
        $this->assertDatabaseCount('management_execution_corrections', 1);

        // Consulta: ambos hechos operativos figuran en la cronología.
        $entries = collect($this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos')->assertOk()->json('data'));
        $this->assertNotNull($entries->firstWhere('id', $applicationId));
        $this->assertNotNull($entries->firstWhere('type', 'management_correction'));
    }

    // Flujo: el saldo es consultable, protegido e idempotente al ajustarse.
    public function test_medicine_stock_read_and_adjustment_expose_persistent_warning(): void
    {
        // Preparación: concede únicamente gestión de stock al actor.
        $this->signIn(['management-plans.stock.manage']);
        $medicine = Medicine::factory()->create();
        $key = (string) Str::uuid();
        $payload = ['quantity_delta' => -1, 'reason' => 'Conteo físico incorrecto.'];

        // Request: repite el ajuste sin duplicar movimiento ni auditoría.
        $first = $this->command('POST', '/medicines/'.$medicine->public_id.'/ajustes-stock', $payload, $key)->assertCreated()
            ->assertJsonPath('data.is_negative', true);
        $second = $this->command('POST', '/medicines/'.$medicine->public_id.'/ajustes-stock', $payload, $key)->assertCreated();
        $this->assertEquals($first->json(), $second->json());
        $this->assertDatabaseCount('medicine_stock_movements', 1);
        $this->getJson('/api/v1/medicines/'.$medicine->public_id.'/stock')->assertOk()
            ->assertJsonPath('data.quantity', -1)
            ->assertJsonPath('data.is_negative', true);
    }

    // Flujo: una vacunación que no puede descontar inventario revierte también la aplicación.
    public function test_vaccination_and_inventory_issue_commit_together(): void
    {
        // Preparación: crea una vacuna controlada sin existencias en la ubicación.
        $this->signIn(['management-plans.execute']);
        $flock = $this->flockWithPlan();
        $vaccine = Vaccine::factory()->create();
        $location = StockLocation::factory()->create();

        // Request: solicita consumo superior a lo disponible y obtiene conflicto.
        $this->command('POST', '/flocks/'.$flock->public_id.'/vacunaciones', [
            'vaccine_id' => $vaccine->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'stock_consumption' => ['quantity' => '1', 'stock_location_id' => $location->id],
        ])->assertConflict();
        $this->assertDatabaseCount('vaccination_applications', 0);
        $this->assertDatabaseCount('inventory_movements', 0);

        // Mutación: repone dos unidades y confirma aplicación más descuento juntos.
        StockBalance::factory()->create([
            'product_id' => $vaccine->product_id,
            'stock_location_id' => $location->id,
            'on_hand_quantity' => '2.000000',
        ]);
        $created = $this->command('POST', '/flocks/'.$flock->public_id.'/vacunaciones', [
            'vaccine_id' => $vaccine->public_id,
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'stock_consumption' => ['quantity' => '1', 'stock_location_id' => $location->id],
        ])->assertCreated();
        $this->assertDatabaseHas('vaccination_applications', [
            'public_id' => $created->json('data.vaccination_application.id'),
            'operation_id' => $created->json('data.operation_id'),
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $vaccine->product_id,
            'stock_location_id' => $location->id,
            'on_hand_quantity' => '1.000000',
        ]);
        $this->assertDatabaseCount('inventory_movements', 1);
    }
}
