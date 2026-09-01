<?php

namespace Tests\Feature\Lots;

use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;

final class EggProductionEndpointTest extends LotsTestCase
{
    // Flujo: registra varias clasificaciones y descarta huevos antes de ingresarlos al stock.
    public function test_records_multiline_collection_and_initial_discard(): void
    {
        // Preparación: configura un lote, dos productos de huevos y una ubicación activa.
        $this->signIn();
        $flock = $this->flock();
        $first = Product::factory()->create(['kind' => ProductKind::Egg, 'base_unit' => BaseUnit::Unit]);
        $second = Product::factory()->create(['kind' => ProductKind::Egg, 'base_unit' => BaseUnit::Unit]);
        $location = StockLocation::factory()->create();

        // Request: registra veinte huevos, dos descartados y dos clasificaciones.
        $response = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [
            'version' => 1,
            'cantidad_recolectada' => 20,
            'cantidad_descartada' => 2,
            'motivo_descarte' => 'Cáscaras rotas en el galpón.',
            'lineas' => [
                ['producto_id' => $first->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 11],
                ['producto_id' => $second->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 7],
            ],
        ])->assertCreated();

        // Consulta: comprueba la cabecera, las líneas y los saldos independientes por clasificación.
        $response->assertJsonPath('data.recoleccion.cantidad_recolectada', 20)
            ->assertJsonPath('data.recoleccion.cantidad_descartada', 2)
            ->assertJsonCount(2, 'data.recoleccion.lineas');
        $this->assertDatabaseHas('stock_balances', ['product_id' => $first->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '11.000000']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $second->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '7.000000']);
    }

    // Flujo: registra una pérdida posterior, descuenta stock y permite revertirla sin borrar historia.
    public function test_records_and_cancels_post_collection_loss(): void
    {
        // Preparación: registra una colección utilizable en una única clasificación.
        $this->signIn();
        $flock = $this->flock();
        $product = Product::factory()->create(['kind' => ProductKind::Egg, 'base_unit' => BaseUnit::Unit]);
        $location = StockLocation::factory()->create();
        $collection = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [
            'version' => 1,
            'cantidad_recolectada' => 12,
            'lineas' => [['producto_id' => $product->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 12]],
        ])->assertCreated();
        $collectionId = $collection->json('data.recoleccion.id');

        // Request: registra una merma posterior de tres huevos.
        $loss = $this->command('POST', "/recolecciones/{$collectionId}/perdidas", [
            'lineas' => [['producto_id' => $product->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 3]],
            'motivo' => 'Rotura en cámara.',
        ])->assertCreated();

        // Consulta: verifica el descuento, el vínculo y la métrica neta.
        $lossId = $loss->json('data.perdida.id');
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '9.000000']);
        $this->assertDatabaseHas('inventory_movements', ['id' => $lossId, 'reference_type' => 'egg_collection', 'reference_id' => $collectionId, 'type' => 'loss']);
        $this->getJson('/api/v1/recolecciones/metricas?fecha_desde='.now()->toDateString().'&fecha_hasta='.now()->toDateString())
            ->assertOk()->assertJsonPath('data.huevos_perdidos_posteriores', 3)->assertJsonPath('data.huevos_netos', 9);

        // Request: revierte la pérdida y conserva ambos movimientos en el historial.
        $this->command('POST', "/recolecciones/{$collectionId}/perdidas/{$lossId}/cancelacion", [
            'motivo' => 'Pérdida cargada por error.',
        ])->assertOk();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '12.000000']);
        $this->assertDatabaseHas('inventory_movements', ['reverses_movement_id' => $lossId, 'type' => 'reversal']);
        $this->getJson('/api/v1/recolecciones/metricas?fecha_desde='.now()->toDateString().'&fecha_hasta='.now()->toDateString())
            ->assertOk()->assertJsonPath('data.huevos_perdidos_posteriores', 0)->assertJsonPath('data.huevos_netos', 12);
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    // Flujo: una colección completamente descartada no crea un ingreso de inventario.
    public function test_all_discarded_collection_does_not_increase_stock(): void
    {
        // Preparación: configura un lote sin clasificaciones utilizables.
        $this->signIn();
        $flock = $this->flock();

        // Request: registra cinco huevos descartados en su totalidad.
        $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [
            'version' => 1,
            'cantidad_recolectada' => 5,
            'cantidad_descartada' => 5,
            'motivo_descarte' => 'Contaminación.',
            'lineas' => [],
        ])->assertCreated()->assertJsonPath('data.recoleccion.cantidad_neta', 0);

        // Consulta: el histórico conserva la cabecera, pero no hay movimiento ni saldo de stock.
        $this->assertDatabaseCount('egg_collections', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: rectifica completamente las clasificaciones y respeta el límite de pérdidas ya registradas.
    public function test_reclassification_replaces_lines_and_cannot_reduce_active_loss(): void
    {
        // Preparación: crea una colección distribuida entre dos productos.
        $this->signIn();
        $flock = $this->flock();
        $first = Product::factory()->create(['kind' => ProductKind::Egg, 'base_unit' => BaseUnit::Unit]);
        $second = Product::factory()->create(['kind' => ProductKind::Egg, 'base_unit' => BaseUnit::Unit]);
        $location = StockLocation::factory()->create();
        $collection = $this->command('POST', "/lotes/{$flock->public_id}/recolecciones", [
            'version' => 1,
            'cantidad_recolectada' => 15,
            'lineas' => [
                ['producto_id' => $first->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 10],
                ['producto_id' => $second->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 5],
            ],
        ])->assertCreated();
        $collectionId = $collection->json('data.recoleccion.id');

        // Request: cambia el reparto a cuatro y nueve huevos, con un descarte inicial.
        $this->command('PATCH', '/recolecciones/'.$collectionId, [
            'version' => 1,
            'version_lote' => 2,
            'cantidad_recolectada' => 14,
            'cantidad_descartada' => 1,
            'motivo_descarte' => 'Recuento de calidad.',
            'lineas' => [
                ['producto_id' => $first->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 4],
                ['producto_id' => $second->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 9],
            ],
            'motivo' => 'Corrección de clasificación.',
        ])->assertOk()->assertJsonPath('data.recoleccion.cantidad_neta', 13);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $first->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '4.000000']);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $second->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '9.000000']);

        // Request: una pérdida activa fija el mínimo que puede conservarse al reclasificar.
        $this->command('POST', "/recolecciones/{$collectionId}/perdidas", [
            'lineas' => [['producto_id' => $second->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 3]],
            'motivo' => 'Rotura.',
        ])->assertCreated();
        $this->command('PATCH', '/recolecciones/'.$collectionId, [
            'version' => 2,
            'version_lote' => 3,
            'cantidad_recolectada' => 14,
            'cantidad_descartada' => 8,
            'motivo_descarte' => 'Nuevo recuento.',
            'lineas' => [
                ['producto_id' => $first->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 4],
                ['producto_id' => $second->id, 'ubicacion_stock_id' => $location->id, 'cantidad' => 2],
            ],
            'motivo' => 'No debe reducir la pérdida activa.',
        ])->assertConflict();
    }
}
