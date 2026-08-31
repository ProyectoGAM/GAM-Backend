<?php

namespace Tests\Feature\Lots;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Actions\RecordInventoryMovementAction;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EggCollectionEndpointTest extends LotsTestCase
{
    /** @return array{cantidad: int, producto_id: int, ubicacion_stock_id: int, version: int} */
    private function payload(int $quantity = 12): array
    {
        return [
            'cantidad' => $quantity, 'version' => 1,
            'producto_id' => Product::factory()->create(['kind' => ProductKind::Egg, 'base_unit' => BaseUnit::Unit])->id,
            'ubicacion_stock_id' => StockLocation::factory()->create()->id,
        ];
    }

    // Flujo: registra la recolección y el ingreso de inventario con la misma operación.
    public function test_collection_and_stock_are_atomic_and_share_operation_identity(): void
    {
        // Preparación: configura lote, producto de huevos y almacén.
        $this->signIn();
        $flock = $this->flock();
        $payload = $this->payload();

        // Request: registra doce huevos sin modificar la cantidad de aves.
        $response = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $payload)
            ->assertCreated()->assertJsonPath('data.recoleccion.cantidad', 12)->assertJsonPath('data.lote.cantidad_viva', 100);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $payload['producto_id'], 'on_hand_quantity' => '12.000000']);
        $this->assertDatabaseHas('inventory_movements', ['operation_id' => $response->json('data.id_operacion'), 'reference_type' => 'egg_collection', 'reference_id' => $response->json('data.recoleccion.id')]);
        $this->assertSame(2, DB::table('activity_log')->where('operation_id', $response->json('data.id_operacion'))->count());
        $this->assertDatabaseCount('flock_movements', 0);
    }

    // Flujo: aplica diferencias de stock al corregir y cancelar una recolección.
    public function test_correction_and_cancellation_append_inventory_compensations(): void
    {
        // Preparación: registra doce unidades y conserva el movimiento original.
        $this->signIn();
        $flock = $this->flock();
        $created = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $this->payload())->assertCreated();
        $url = '/recolecciones/'.$created->json('data.recoleccion.id');
        $original = InventoryMovement::query()->firstOrFail()->getAttributes();

        // Request: reduce la recolección a diez mediante un ajuste de menos dos.
        $this->command('PATCH', $url, ['version' => 1, 'version_lote' => 2, 'cantidad' => 10, 'motivo' => 'Recuento confirmado'])
            ->assertOk()->assertJsonPath('data.recoleccion.cantidad', 10);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '10.000000']);
        $this->assertDatabaseHas('inventory_movement_lines', ['on_hand_delta' => '-2.000000']);

        // Request: cancela sin borrar la recolección ni los movimientos anteriores.
        $this->command('POST', $url.'/cancelacion', ['version' => 2, 'version_lote' => 3, 'motivo' => 'Duplicado'])
            ->assertOk()->assertJsonPath('data.recoleccion.estado', 'cancelled');
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '0.000000']);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseCount('egg_collections', 1);
        $this->assertEquals($original, InventoryMovement::query()->firstOrFail()->getAttributes());
        $this->assertDatabaseHas('activity_log', ['event' => 'egg_collection_cancelled']);
    }

    // Flujo: conserva el stock cuando sólo se rectifican las observaciones.
    public function test_note_only_correction_does_not_create_zero_inventory_movements(): void
    {
        // Preparación: registra una recolección.
        $this->signIn();
        $flock = $this->flock();
        $created = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $this->payload())->assertCreated();

        // Request: corrige texto y audita el cambio sin alterar cantidades.
        $this->command('PATCH', '/recolecciones/'.$created->json('data.recoleccion.id'), [
            'version' => 1, 'version_lote' => 2, 'observaciones' => 'Turno de la mañana', 'motivo' => 'Completar información',
        ])->assertOk()->assertJsonPath('data.recoleccion.observaciones', 'Turno de la mañana');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '12.000000']);
    }

    // Flujo: impide cancelar huevos que ya fueron consumidos por otro proceso.
    public function test_cancellation_cannot_remove_consumed_stock(): void
    {
        // Preparación: registra doce huevos y retira diez por el motor de inventario.
        $actor = $this->signIn();
        $flock = $this->flock();
        $payload = $this->payload();
        $created = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $payload)->assertCreated();
        $this->app->make(RecordInventoryMovementAction::class)->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Issue,
            lines: [['product_id' => $payload['producto_id'], 'stock_location_id' => $payload['ubicacion_stock_id'], 'on_hand_delta' => '-10']],
            operationId: (string) Str::uuid(),
        ), $actor);

        // Request: el conflicto de stock revierte también la corrección de producción.
        $this->command('POST', '/recolecciones/'.$created->json('data.recoleccion.id').'/cancelacion', [
            'version' => 1, 'version_lote' => 2, 'motivo' => 'Intento de cancelación sin saldo',
        ])->assertConflict();
        $this->assertDatabaseHas('egg_collections', ['status' => 'recorded', 'version' => 1, 'quantity' => 12]);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '2.000000']);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame(2, $flock->fresh()->version);
    }

    // Flujo: una categoría de producto equivocada o un almacén inactivo no generan stock.
    public function test_product_and_location_requirements_are_enforced(): void
    {
        // Preparación: prepara referencias válidas y luego invalida una a la vez.
        $this->signIn();
        $flock = $this->flock();
        $payload = $this->payload();
        $raw = Product::factory()->create();

        // Requests: rechaza producto no huevo y ubicación inactiva.
        $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [...$payload, 'producto_id' => $raw->id])->assertConflict();
        StockLocation::query()->findOrFail($payload['ubicacion_stock_id'])->forceFill(['status' => 'inactive'])->save();
        $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $payload)->assertConflict();
        $this->assertDatabaseCount('egg_collections', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    // Flujo: una falla al auditar Lotes revierte un ingreso de inventario ya ejecutado.
    public function test_lots_audit_failure_rolls_back_inventory_and_collection(): void
    {
        // Preparación: permite la primera auditoría de inventario y falla la de Lotes.
        $this->signIn();
        $flock = $this->flock();
        $payload = $this->payload();
        $realAudit = $this->app->make(AuditRecorder::class);
        $calls = 0;
        $this->mock(AuditRecorder::class)->shouldReceive('record')->twice()->andReturnUsing(function ($entry) use ($realAudit, &$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('Fallo controlado después del ingreso de inventario');
            }

            return $realAudit->record($entry);
        });

        // Request: verifica rollback de todas las escrituras, incluida la primera auditoría.
        $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $payload)->assertStatus(500);
        $this->assertDatabaseCount('egg_collections', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('activity_log', 0);
        $this->assertSame(1, $flock->fresh()->version);
    }

    // Flujo: las métricas usan fechas locales y excluyen las recolecciones canceladas.
    public function test_metrics_use_local_days_and_current_corrected_projection(): void
    {
        // Preparación: fija el reloj para registrar a ambos lados de la medianoche local.
        $this->travelTo(now()->setDate(2026, 8, 25)->setTime(15, 0));
        $this->signIn();
        $flock = $this->flock();
        $payload = $this->payload(10);
        $first = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [...$payload, 'ocurrido_en' => '2026-08-25T01:00:00+00:00'])->assertCreated();
        $second = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [...$payload, 'version' => 2, 'cantidad' => 20, 'ocurrido_en' => '2026-08-25T12:00:00+00:00'])->assertCreated();

        // Consulta: el promedio incluye ambos días, aunque no tengan igual producción.
        $url = "/api/v1/lotes/{$flock->public_id}/metricas?fecha_desde=2026-08-24&fecha_hasta=2026-08-25";
        $this->getJson($url)->assertOk()->assertJsonPath('data.huevos_totales', 30)
            ->assertJsonPath('data.por_dia.0.fecha', '2026-08-24')->assertJsonPath('data.por_dia.0.cantidad', 10)
            ->assertJsonPath('data.promedio_diario', 15);

        // Mutación: cancela la segunda recolección y verifica la proyección de consulta.
        $this->command('POST', '/recolecciones/'.$second->json('data.recoleccion.id').'/cancelacion', [
            'version' => 1, 'version_lote' => 3, 'motivo' => 'Registro duplicado',
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.huevos_totales', 10)->assertJsonPath('data.promedio_diario', 5);
        $this->getJson("/api/v1/lotes/{$flock->public_id}/recolecciones?estado=cancelled")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/lotes/{$flock->public_id}/metricas?fecha_desde=2024-01-01&fecha_hasta=2026-01-01")->assertConflict();
    }

    // Flujo: reintentar la captura no duplica huevos, auditoría ni movimientos.
    public function test_offline_replay_returns_original_collection(): void
    {
        // Preparación: fija una clave de idempotencia compartida entre los intentos.
        $this->signIn();
        $flock = $this->flock();
        $payload = $this->payload();
        $key = (string) Str::uuid();

        // Requests: repite la recolección original.
        $first = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $payload, $key)->assertCreated();
        $second = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", $payload, $key)->assertCreated();
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('egg_collections', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '12.000000']);
    }

    // Flujo: autoriza registrar durante cuarentena, pero no en lotes vacíos o finalizados.
    public function test_collection_obeys_flock_lifecycle(): void
    {
        // Preparación: configura lotes en tres situaciones distintas.
        $this->signIn();
        $quarantine = Flock::factory()->quarantined()->create();
        $empty = Flock::factory()->create(['current_quantity' => 0]);
        $finished = Flock::factory()->finished()->create();
        $payload = $this->payload();

        // Requests: sólo el lote en cuarentena con aves puede producir.
        $this->command('POST', "/lotes/{$quarantine->public_id}/recolecciones", $payload)->assertCreated();
        $this->command('POST', "/lotes/{$empty->public_id}/recolecciones", $payload)->assertConflict();
        $this->command('POST', "/lotes/{$finished->public_id}/recolecciones", $payload)->assertConflict();
        $this->assertDatabaseCount('egg_collections', 1);
    }
}
