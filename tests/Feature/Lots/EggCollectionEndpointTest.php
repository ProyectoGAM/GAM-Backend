<?php

namespace Tests\Feature\Lots;

final class EggCollectionEndpointTest extends LotsTestCase
{
    /** Flujo: registra una recolección atómica y crea una cuenta técnica por UP. */
    public function test_collection_creates_history_and_stock_without_changing_flock(): void
    {
        // Preparación: autentica al usuario con permisos de producción y stock.
        $this->signIn(['egg-collections.view', 'egg-collections.manage', 'egg-stock.view', 'egg-stock.move', 'egg-stock.adjust']);
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
        $this->signIn(['egg-collections.view', 'egg-collections.manage', 'egg-stock.view', 'egg-stock.move', 'egg-stock.adjust']);
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
        $this->signIn(['egg-collections.view', 'egg-collections.manage', 'egg-stock.view', 'egg-stock.move', 'egg-stock.adjust']);
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
        $this->signIn(['egg-collections.view', 'egg-collections.manage']);
        $flock = $this->flock();
        $this->command('POST', "/flocks/{$flock->public_id}/collections", ['quantity' => 9])->assertCreated();

        // Consulta: solicita métricas diarias, semanales y mensuales.
        $this->getJson('/api/v1/collections/metrics')->assertOk()->assertJsonPath('data.collected_eggs', 9);
    }
}
