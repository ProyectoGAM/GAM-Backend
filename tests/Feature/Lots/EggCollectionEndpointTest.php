<?php

namespace Tests\Feature\Lots;

use App\Models\Lots\EggCollection;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;

final class EggCollectionEndpointTest extends LotsTestCase
{
    #[TestWith([0])]
    #[TestWith([-1])]
    // Flujo: no registra producción cuando el lote abierto tiene saldo cero o negativo.
    public function test_open_flock_without_positive_balance_cannot_record_eggs(int $currentQuantity): void
    {
        // Preparación: configura un lote abierto sin población física positiva.
        $this->signIn();
        $flock = $this->flock(10);
        $flock->forceFill(['current_quantity' => $currentQuantity])->save();

        // Request: rechaza la producción antes de crear recolecciones, stock o auditoría.
        $this->command('POST', "/flocks/{$flock->public_id}/collections", ['quantity' => 1])->assertConflict();
        $this->assertDatabaseCount('egg_collections', 0);
        $this->assertDatabaseCount('egg_stock_transactions', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** Flujo: registra una recolección atómica y crea una cuenta técnica por UP. */
    public function test_collection_creates_history_and_stock_without_changing_flock(): void
    {
        // Preparación: autentica al usuario con permisos de producción y stock.
        $this->signIn(['can_view_egg_collections', 'can_manage_egg_collections', 'can_view_egg_stock', 'can_move_egg_stock', 'can_adjust_egg_stock']);
        $flock = $this->flock();
        $version = $flock->version;

        // Request: registra huevos genéricos sin clasificaciones ni ubicaciones públicas.
        $response = $this->command('POST', "/flocks/{$flock->public_id}/collections", ['quantity' => 12])->assertCreated();

        // Verificación: la producción histórica es independiente de la versión del lote.
        $response->assertJsonPath('data.collection.quantity', 12);
        $this->assertSame($version, $flock->fresh()->version);
        $this->assertDatabaseHas('egg_stock_transactions', ['type' => 'collection_receipt', 'quantity' => 12]);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '12.000000', 'allow_negative' => true]);
    }

    /** Flujo: corrige una captura de 4000 a 400 y conserva la fecha efectiva. */
    public function test_collection_correction_applies_only_the_difference(): void
    {
        // Preparación: registra la captura inicial y conserva su versión.
        $this->signIn(['can_view_egg_collections', 'can_manage_egg_collections', 'can_view_egg_stock', 'can_move_egg_stock', 'can_adjust_egg_stock']);
        $flock = $this->flock();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/collections", ['quantity' => 4000])->assertCreated();
        $collectionId = $created->json('data.collection.id');

        // Request: corrige la cantidad con el motivo obligatorio.
        $this->command('PATCH', "/collections/{$collectionId}", ['version' => 1, 'quantity' => 400, 'correction_reason' => 'Error de digitación'])->assertOk();

        // Verificación: el saldo queda en cuatrocientas unidades y existe una revisión append-only.
        $this->assertDatabaseHas('egg_collections', ['public_id' => $collectionId, 'quantity' => 400, 'version' => 2]);
        $this->assertDatabaseHas('egg_stock_transactions', ['reference_id' => $collectionId, 'quantity' => 400, 'version' => 2]);
        $this->assertDatabaseCount('egg_stock_transaction_revisions', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '400.000000']);
    }

    /** Flujo: cancela lógicamente una recolección y registra la compensación inversa. */
    public function test_collection_cancellation_keeps_history_and_can_make_balance_negative(): void
    {
        // Preparación: registra una recolección en un lote válido.
        $this->signIn(['can_view_egg_collections', 'can_manage_egg_collections', 'can_view_egg_stock', 'can_move_egg_stock', 'can_adjust_egg_stock']);
        $flock = $this->flock();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/collections", ['quantity' => 12])->assertCreated();
        $collectionId = $created->json('data.collection.id');

        // Request: cancela la operación sin eliminar el registro histórico.
        $this->command('POST', "/collections/{$collectionId}/cancellation", ['version' => 1, 'correction_reason' => 'Captura inválida'])->assertOk();

        // Verificación: la transacción queda cancelada y el movimiento inverso conserva auditoría.
        $this->assertDatabaseHas('egg_collections', ['public_id' => $collectionId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('egg_stock_transactions', ['reference_id' => $collectionId, 'status' => 'cancelled']);
        $this->assertDatabaseCount('egg_stock_transaction_revisions', 1);
        $this->assertDatabaseHas('stock_balances', ['on_hand_quantity' => '0.000000']);
    }

    /** Flujo: expone métricas sólo de recolecciones registradas. */
    public function test_metrics_ignore_manual_stock_movements(): void
    {
        // Preparación: registra una única recolección.
        $this->signIn(['can_view_egg_collections', 'can_manage_egg_collections']);
        $flock = $this->flock();
        $this->command('POST', "/flocks/{$flock->public_id}/collections", ['quantity' => 9])->assertCreated();

        // Consulta: solicita métricas diarias, semanales y mensuales.
        $this->getJson('/api/v1/collections/metrics')->assertOk()->assertJsonPath('data.collected_eggs', 9)
            ->assertJsonMissingPath('data.comparacion');
    }

    // Flujo: compara períodos contiguos con la misma UP, sin incluir anulaciones ni extender las series.
    public function test_metrics_compare_the_previous_calendar_period(): void
    {
        // Preparación: coloca registros a ambos lados de la medianoche y en una segunda UP.
        config(['lots.timezone' => 'America/Montevideo']);
        $this->signIn(['can_view_egg_collections']);
        $flock = $this->flock();
        $other = $this->flock();
        foreach ([[$flock, '2026-11-01T02:59:59Z', 10, 'recorded'], [$flock, '2026-11-01T03:00:00Z', 15, 'recorded'],
            [$flock, '2026-11-02T03:00:00Z', 99, 'recorded'], [$flock, '2026-11-01T04:00:00Z', 90, 'cancelled'],
            [$other, '2026-11-01T02:59:59Z', 50, 'recorded']] as [$lot, $date, $quantity, $status]) {
            EggCollection::factory()->for($lot)->create(['occurred_at' => CarbonImmutable::parse($date), 'quantity' => $quantity, 'status' => $status]);
        }

        // Consulta: el 1 de noviembre compara únicamente el 31 de octubre y devuelve la serie actual.
        $path = '/api/v1/collections/metrics?comparar_anterior=1&production_unit_id='.$flock->production_unit_id;
        $this->getJson($path.'&date_from=2026-11-01&date_to=2026-11-01')->assertOk()
            ->assertJsonPath('data.total_eggs', 15)->assertJsonCount(1, 'data.by_day')
            ->assertJsonPath('data.comparacion', ['desde' => '2026-10-31', 'hasta' => '2026-10-31', 'total_huevos' => 10, 'variacion_porcentual' => 50]);

        // Consulta: un período anterior vacío conserva el total cero y devuelve variación nula.
        $this->getJson($path.'&date_from=2026-10-31&date_to=2026-10-31')->assertOk()
            ->assertJsonPath('data.comparacion.total_huevos', 0)->assertJsonPath('data.comparacion.variacion_porcentual', null);
    }
}
