<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CancelFeedManufactureAction;
use App\Actions\Inventory\ManufactureFeedAction;
use App\Actions\Inventory\RecordInventoryMovementAction;
use App\Actions\Inventory\ReverseInventoryMovementAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Enums\Inventory\InventoryMovementType;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SharedDevice;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FeedManufactureEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: distribuye cada ingrediente por separado y acredita el rendimiento independiente.
    public function test_distributes_selected_first_then_smallest_balance_per_ingredient(): void
    {
        // Preparación: A tiene 2000/500, B 3000/8000 y C 8000/1000 gramos.
        $data = $this->fixture();
        $this->freezeTime();

        // Request: elabora una combinación completa sin cálculo previo.
        $response = $this->manufacture($data)->assertCreated();
        $response->assertJsonPath('data.factor', '1')->assertJsonPath('data.saldo_resultante_g', '13000');
        $distribution = $response->json('data.distribucion');
        $this->assertSame([
            [$data['products'][0]->id, $data['plants'][0]->id, '2000.000000'],
            [$data['products'][0]->id, $data['plants'][1]->id, '3000.000000'],
            [$data['products'][0]->id, $data['plants'][2]->id, '5000.000000'],
            [$data['products'][1]->id, $data['plants'][0]->id, '500.000000'],
            [$data['products'][1]->id, $data['plants'][2]->id, '1000.000000'],
            [$data['products'][1]->id, $data['plants'][1]->id, '500.000000'],
        ], array_map(fn (array $line): array => [$line['producto_id'], $line['planta_id'], $line['cantidad_g']], $distribution));
        $this->assertDatabaseHas('stock_balances', ['id' => $data['balances'][2][0]->id, 'on_hand_quantity' => '3000.000000']);
        $this->assertDatabaseHas('stock_balances', ['id' => $data['balances'][1][1]->id, 'on_hand_quantity' => '7500.000000']);
        $this->assertDatabaseCount('inventory_movements', 1);

        // Consulta: auditoría, operación y líneas comparten la misma referencia y traza.
        $audit = AuditEntry::query()->where('event', 'feed_manufacture_recorded')->sole();
        $this->assertSame($response->json('data.operacion_id'), $audit->operation_id);
        $this->assertSame($response->headers->get('X-Trace-Id'), $audit->trace_id);
        $this->assertSame($data['actor']->id, $audit->causer_id);
        $this->assertSame($data['plants'][0]->production_unit_id, $audit->up_id);
        $this->assertSame('0.000000', $audit->attribute_changes['old']['quantity_grams']);
        $this->assertCount(6, $audit->properties['subject_snapshot']['snapshot']['distribution']);
        $this->assertSame('2000.000000', $audit->attribute_changes['old']['ingredients'][0]['quantity_grams']);
        $this->assertSame('0.000000', $audit->attribute_changes['new']['ingredients'][0]['quantity_grams']);
        $this->assertSame(now()->toIso8601String(), $response->json('data.registrado_en'));
    }

    // Flujo: desempata por ID y excluye cero, negativos y plantas de otra UP o no operativas.
    public function test_only_operational_same_up_positive_balances_are_eligible_with_id_ties(): void
    {
        // Preparación: iguala B/C y crea una planta ajena con mucho stock.
        $data = $this->fixture();
        $data['balances'][0][0]->forceFill(['on_hand_quantity' => '-100'])->save();
        $data['balances'][1][0]->forceFill(['on_hand_quantity' => '5000'])->save();
        $data['balances'][2][0]->forceFill(['on_hand_quantity' => '5000'])->save();
        $foreign = PoultryHouse::factory()->feed()->create();
        $foreignLocation = $this->location($foreign);
        StockBalance::factory()->create(['product_id' => $data['products'][0]->id, 'stock_location_id' => $foreignLocation->id, 'on_hand_quantity' => '90000']);
        foreach ([PoultryHouseStatus::Maintenance, PoultryHouseStatus::OutOfService, PoultryHouseStatus::Inactive] as $status) {
            $excluded = PoultryHouse::factory()->feed()->create(['production_unit_id' => $data['plants'][0]->production_unit_id, 'status' => $status]);
            StockBalance::factory()->create(['product_id' => $data['products'][0]->id, 'stock_location_id' => $this->location($excluded)->id, 'on_hand_quantity' => '90000']);
        }

        // Request: consume sólo B y C, en orden de ID.
        $response = $this->manufacture($data)->assertCreated();
        $this->assertSame([$data['plants'][1]->id, $data['plants'][2]->id], array_column(array_slice($response->json('data.distribucion'), 0, 2), 'planta_id'));
        $this->assertDatabaseHas('stock_balances', ['id' => $data['balances'][0][0]->id, 'on_hand_quantity' => '-100.000000']);
        $this->assertDatabaseHas('stock_balances', ['stock_location_id' => $foreignLocation->id, 'on_hand_quantity' => '90000.000000']);

        // Request: la segunda elaboración rechaza aunque otras UP o plantas inactivas tengan stock.
        $this->manufacture($data)->assertConflict();
        $this->assertDatabaseCount('feed_stock_operations', 1);
    }

    // Flujo: un ingrediente insuficiente revierte todos los efectos del conjunto.
    public function test_returns_409_for_insufficient_ingredient_without_partial_effects(): void
    {
        // Preparación: deja el segundo ingrediente sin existencias.
        $data = $this->fixture();
        foreach ($data['balances'] as $balances) {
            $balances[1]->forceFill(['on_hand_quantity' => 0])->save();
        }

        // Request: la cantidad del primer ingrediente alcanza, pero la operación completa falla.
        $this->manufacture($data)->assertConflict();
        $this->assertDatabaseHas('stock_balances', ['id' => $data['balances'][0][0]->id, 'on_hand_quantity' => '2000.000000']);
        $this->assertDatabaseCount('feed_balances', 0);
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: rechaza recetas o ingredientes inactivos y plantas no operativas.
    #[DataProvider('blockedStates')]
    public function test_returns_409_for_inactive_recipe_ingredient_or_selected_plant(string $target, string $state): void
    {
        // Preparación: aplica el estado excluido a una sola entidad.
        $data = $this->fixture();
        $model = match ($target) {
            'recipe' => $data['recipe'], 'product' => $data['products'][0], 'plant' => $data['plants'][0],
        };
        $model->forceFill(['status' => $state])->save();

        // Request: no registra elaboración ni movimiento.
        $this->manufacture($data)->assertConflict();
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /** @return array<string, array{string,string}> */
    public static function blockedStates(): array
    {
        return [
            'receta' => ['recipe', 'inactive'], 'ingrediente' => ['product', 'inactive'],
            'mantenimiento' => ['plant', 'maintenance'], 'fuera de servicio' => ['plant', 'out_of_service'],
            'inactiva' => ['plant', 'inactive'],
        ];
    }

    // Flujo: acumula por receta y UP sin separar por planta ni modificar historia al editar.
    public function test_accumulates_by_recipe_and_up_and_preserves_composition_after_edit(): void
    {
        // Preparación: provee stock para elaborar varias combinaciones desde plantas distintas.
        $data = $this->fixture();
        foreach ($data['balances'] as $balances) {
            foreach ($balances as $balance) {
                $balance->forceFill(['on_hand_quantity' => 100000])->save();
            }
        }

        // Requests: realiza dos elaboraciones de una receta desde dos plantas de la misma UP.
        $first = $this->manufacture($data)->assertCreated();
        $data['plants'][0] = $data['plants'][1];
        $this->manufacture($data)->assertCreated()->assertJsonPath('data.saldo_resultante_g', '26000');

        // Mutación: cambia la fórmula y desactiva la receta sin tocar el saldo.
        $data['recipe']->ingredients()->where('product_id', $data['products'][0]->id)->update(['quantity_grams' => 20000]);
        $data['recipe']->forceFill(['status' => FeedRecipeStatus::Inactive])->save();

        // Consulta: devuelve el saldo y la fórmula original aunque el catálogo esté inactivo.
        $this->getJson('/api/v1/saldos-alimento?receta_id='.$data['recipe']->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.saldo_g', '26000')->assertJsonPath('data.0.estado_receta', 'inactive');
        $this->getJson('/api/v1/elaboraciones/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.ingredientes.0.cantidad_base_g', '10000');
        $this->getJson('/api/v1/elaboraciones?por_pagina=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    }

    // Flujo: cancela exactamente la composición original aunque receta e ingredientes estén inactivos.
    public function test_cancellation_restores_exact_original_lines_and_does_not_reactivate_products(): void
    {
        // Preparación: conserva los saldos previos y la elaboración original.
        $data = $this->fixture();
        $created = $this->manufacture($data)->assertCreated();
        $id = $created->json('data.id');
        $original = FeedStockOperation::query()->findOrFail($id)->getAttributes();
        $data['recipe']->ingredients()->update(['quantity_grams' => 99]);
        $data['recipe']->forceFill(['status' => FeedRecipeStatus::Inactive])->save();
        foreach ($data['products'] as $product) {
            $product->forceFill(['status' => ProductStatus::Inactive])->save();
        }
        $headers = $this->headers();

        // Requests: cancela y reintenta con la misma clave sin duplicar devoluciones.
        $cancelled = $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Corrección'], $headers)->assertOk();
        $cancelled->assertJsonPath('data.estado', 'cancelada')->assertJsonPath('data.cancelacion.saldo_resultante_g', '0');
        $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Corrección'], $headers)->assertOk();
        $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Otra corrección'], $this->headers())->assertConflict();

        // Consulta: la historia original permanece idéntica y los productos siguen inactivos.
        $this->assertSame($original, FeedStockOperation::query()->findOrFail($id)->getAttributes());
        $this->assertSame(ProductStatus::Inactive, $data['products'][0]->fresh()->status);
        foreach ([[0, 0, '2000.000000'], [0, 1, '500.000000'], [1, 0, '3000.000000'], [1, 1, '8000.000000'], [2, 0, '8000.000000'], [2, 1, '1000.000000']] as [$plant, $product, $expected]) {
            $this->assertSame($expected, $data['balances'][$plant][$product]->fresh()->on_hand_quantity);
        }
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('feed_stock_operations', 2);
        $this->assertSame(1, AuditEntry::query()->where('event', 'feed_cancellation_recorded')->count());
        $this->assertDatabaseHas('inventory_movements', ['reverses_movement_id' => $created->json('data.movimiento_id')]);
    }

    // Flujo: cada referencia de devolución pertenece al movimiento compensatorio en API, historial y auditoría.
    public function test_cancellation_distribution_references_compensating_movement_lines(): void
    {
        // Preparación: genera seis descuentos con productos y plantas en prioridades diferentes.
        $data = $this->fixture();
        $created = $this->manufacture($data)->assertCreated();
        $id = $created->json('data.id');
        $original = FeedStockOperation::query()->findOrFail($id)->getAttributes();
        $originalAudit = AuditEntry::query()->where('event', 'feed_manufacture_recorded')->sole()->toArray();
        $headers = $this->headers();

        // Request: cancela y recupera las referencias públicas de las seis devoluciones.
        $cancelled = $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Corrección'], $headers)->assertOk();
        $cancelled->assertJsonCount(6, 'data.cancelacion.distribucion');
        $movementId = $cancelled->json('data.cancelacion.movimiento_id');
        $distribution = $cancelled->json('data.cancelacion.distribucion');
        foreach ($distribution as $line) {
            $this->assertDatabaseHas('inventory_movement_lines', [
                'id' => $line['linea_movimiento_id'],
                'inventory_movement_id' => $movementId,
                'product_id' => $line['producto_id'],
                'stock_location_id' => $line['ubicacion_id'],
                'on_hand_delta' => $line['cantidad_g'],
            ]);
        }

        // Consulta: la instantánea auditada coincide con la persistida y utiliza las referencias compensatorias.
        $operation = FeedStockOperation::query()->where('cancels_operation_id', $id)->sole();
        $audit = AuditEntry::query()->where('event', 'feed_cancellation_recorded')->sole();
        $this->assertSame($movementId, (int) $audit->properties['subject_snapshot']['inventory_movement_id']);
        $this->assertSame($operation->snapshot, $audit->properties['subject_snapshot']['snapshot']);
        $this->assertSame(array_column($distribution, 'linea_movimiento_id'), array_column($operation->snapshot['distribution'], 'movement_line_id'));
        $this->assertSame($original, FeedStockOperation::query()->findOrFail($id)->getAttributes());
        $this->assertSame($originalAudit, AuditEntry::query()->where('event', 'feed_manufacture_recorded')->sole()->toArray());

        // Request: el reintento conserva esas mismas referencias sin duplicar movimiento ni auditoría.
        $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Corrección'], $headers)
            ->assertOk()->assertJsonPath('data.cancelacion.distribucion', $distribution);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame(1, AuditEntry::query()->where('event', 'feed_cancellation_recorded')->count());
    }

    // Flujo: rechaza devoluciones si el alimento quedaría negativo o una planta original no opera.
    public function test_returns_409_for_cancellation_with_insufficient_feed_or_non_operational_recipient(): void
    {
        // Preparación: elabora y reduce el alimento disponible por un ajuste independiente.
        $data = $this->fixture();
        $created = $this->manufacture($data)->assertCreated();
        $id = $created->json('data.id');
        $this->postJson('/api/v1/unidades-productivas/'.$data['plants'][0]->production_unit_id.'/recetas/'.$data['recipe']->id.'/ajustes',
            ['accion' => 'restar', 'valor_g' => 1, 'motivo' => 'Merma'], $this->headers())->assertCreated();

        // Request: la cancelación no puede devolver ingredientes con saldo de alimento insuficiente.
        $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Corregir'], $this->headers())->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => 12999]);

        // Mutación: repone el alimento, pero pone una planta receptora en mantenimiento.
        FeedBalance::query()->update(['quantity_grams' => 13000]);
        $data['plants'][1]->forceFill(['status' => PoultryHouseStatus::Maintenance])->save();

        // Request: también rechaza la devolución y conserva los descuentos originales.
        $this->postJson("/api/v1/elaboraciones/$id/cancelacion", ['motivo' => 'Corregir'], $this->headers())->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('stock_balances', ['id' => $data['balances'][0][0]->id, 'on_hand_quantity' => '0.000000']);
        $this->assertSame(0, FeedStockOperation::query()->where('kind', 'cancellation')->count());
    }

    // Flujo: el replay no requiere receta ni ingredientes activos y no duplica auditoría.
    public function test_retries_are_canonical_and_changed_payload_returns_409(): void
    {
        // Preparación: conserva la clave de la primera elaboración.
        $data = $this->fixture();
        $headers = $this->headers();
        $payload = ['receta_id' => $data['recipe']->id, 'planta_id' => $data['plants'][0]->id, 'cantidad_g' => '013000'];

        // Requests: repite con entero equivalente después de desactivar la receta.
        $first = $this->postJson('/api/v1/elaboraciones', $payload, $headers)->assertCreated();
        $data['recipe']->forceFill(['status' => FeedRecipeStatus::Inactive])->save();
        $payload['cantidad_g'] = 13000;
        $this->postJson('/api/v1/elaboraciones', $payload, $headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $payload['cantidad_g'] = 26000;
        $this->postJson('/api/v1/elaboraciones', $payload, $headers)->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(1, AuditEntry::query()->where('event', 'feed_manufacture_recorded')->count());
    }

    // Flujo: rechaza formatos aproximados, exceso de persistencia, campos retroactivos y múltiplos inválidos.
    #[DataProvider('invalidQuantities')]
    public function test_returns_422_for_invalid_quantities(mixed $value): void
    {
        // Preparación: autentica y prepara una combinación existente.
        $data = $this->fixture();

        // Request: verifica el límite antes de persistir.
        $this->json('POST', '/api/v1/elaboraciones', ['receta_id' => $data['recipe']->id, 'planta_id' => $data['plants'][0]->id, 'cantidad_g' => $value], $this->headers(), JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('cantidad_g');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidQuantities(): array
    {
        return ['float' => [13000.0], 'decimal' => ['13000.0'], 'científica' => ['13e3'], 'booleano' => [true],
            'cero' => [0], 'negativo' => [-1], 'límite' => ['1000000000000']];
    }

    // Flujo: valida la fórmula vigente y el límite de cada ingrediente multiplicado.
    public function test_rejects_non_multiple_overflowing_ingredient_and_backdated_payload(): void
    {
        // Preparación: conserva una fórmula cuyo rendimiento no es divisor de la primera entrada.
        $data = $this->fixture();

        // Requests: ninguna entrada parcial o retroactiva puede elaborar.
        $this->manufacture($data, 13001)->assertConflict();
        $this->postJson('/api/v1/elaboraciones', ['receta_id' => $data['recipe']->id, 'planta_id' => $data['plants'][0]->id, 'cantidad_g' => 13000, 'fecha' => '2020-01-01'], $this->headers())->assertUnprocessable();
        $data['recipe']->ingredients()->where('product_id', $data['products'][0]->id)->update(['quantity_grams' => 999999999999]);
        $this->manufacture($data, 26000)->assertConflict();
        $this->assertDatabaseCount('feed_stock_operations', 0);
    }

    // Flujo: la falla de auditoría final revierte descuentos, alimento e historial completos.
    public function test_audit_failure_rolls_back_all_manufacturing_effects(): void
    {
        // Preparación: reemplaza sólo el grabador para simular una falla transaccional.
        $data = $this->fixture();
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                if ($entry->event === 'feed_manufacture_recorded') {
                    throw new RuntimeException('Falla de auditoría');
                }
            }
        });

        // Mutación: ejecuta la acción y captura la falla del grabador.
        try {
            app(ManufactureFeedAction::class)->execute($data['recipe']->id, $data['plants'][0]->id, 13000, (string) Str::uuid(), $data['actor']);
            $this->fail('Debió fallar la auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falla de auditoría', $exception->getMessage());
        }
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('feed_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame('2000.000000', $data['balances'][0][0]->fresh()->on_hand_quantity);
    }

    // Flujo: una falla de auditoría revierte íntegramente la cancelación y su compensación.
    public function test_cancellation_audit_failure_preserves_original_debits_and_feed(): void
    {
        // Preparación: elabora y simula una falla al auditar la devolución.
        $data = $this->fixture();
        $created = $this->manufacture($data)->assertCreated();
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                if ($entry->event === 'feed_cancellation_recorded') {
                    throw new RuntimeException('Falla de cancelación');
                }
            }
        });

        // Mutación: la cancelación fallida revierte incluso su movimiento compensatorio.
        try {
            app(CancelFeedManufactureAction::class)->execute($created->json('data.id'), 'Corregir', (string) Str::uuid(), $data['actor']);
            $this->fail('Debió fallar la auditoría de cancelación.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falla de cancelación', $exception->getMessage());
        }
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => 13000]);
        $this->assertSame('0.000000', $data['balances'][0][0]->fresh()->on_hand_quantity);
    }

    // Flujo: mantiene las fracciones históricas de inventario al repartir una necesidad entera.
    public function test_fractional_plant_balances_are_distributed_without_rounding(): void
    {
        // Preparación: cambia sólo el saldo seleccionado, preservando la necesidad de 10000 gramos.
        $data = $this->fixture();
        $data['balances'][0][0]->forceFill(['on_hand_quantity' => '2000.123456'])->save();

        // Request: consume la fracción exacta y el complemento exacto de la última planta.
        $response = $this->manufacture($data)->assertCreated();
        $response->assertJsonPath('data.distribucion.0.cantidad_g', '2000.123456')
            ->assertJsonPath('data.distribucion.2.cantidad_g', '4999.876544');
        $this->assertSame('3000.123456', $data['balances'][2][0]->fresh()->on_hand_quantity);
    }

    // Flujo: exige credencial de dispositivo, sesión vigente y generación actual en acceso compartido.
    public function test_shared_native_session_requires_current_context_and_generation(): void
    {
        // Preparación: registra un empleado, su credencial y una sesión nativa real.
        $actor = User::factory()->create();
        $secret = Str::random(64);
        $device = SharedDevice::query()->create([
            'name' => 'Tablet de prueba', 'credential_hash' => hash('sha256', $secret),
            'credential_expires_at' => now()->addYear(), 'enrolled_by' => $actor->id, 'enrolled_at' => now(),
        ]);
        $token = $actor->createToken('shared-user', ['*'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($actor, $device->refresh(), $token);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Shared-Device-Token' => $device->id.'|'.$secret, 'X-GAM-Session' => $session->id];

        // Requests: permite el contexto completo y rechaza la falta de credencial sin cambiar saldos.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/elaboraciones', $headers)->assertOk();
        $missing = $headers;
        unset($missing['X-Shared-Device-Token']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/elaboraciones', $missing)->assertUnauthorized();

        // Mutación y request: cambiar la generación invalida la sesión anterior.
        $device->increment('session_generation');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/elaboraciones', $headers)->assertUnauthorized();
        $this->assertDatabaseCount('feed_stock_operations', 0);
    }

    // Flujo: la reversión genérica no puede saltarse la cancelación del alimento.
    public function test_generic_reversal_cannot_reverse_manufacturing_ingredients(): void
    {
        // Preparación: crea una elaboración con sus líneas originales.
        $data = $this->fixture();
        $data['actor']->givePermissionTo(Permission::findOrCreate('inventory.adjust', 'web'));
        $created = $this->manufacture($data)->assertCreated();
        $movement = InventoryMovement::query()->findOrFail($created->json('data.movimiento_id'));

        // Mutación: rechaza una reversión independiente del saldo de alimento.
        try {
            app(ReverseInventoryMovementAction::class)->execute($movement, ['operation_id' => (string) Str::uuid(), 'reason' => 'Prueba'], $data['actor']);
            $this->fail('Debió exigir la cancelación de elaboración.');
        } catch (InventoryConflict $exception) {
            $this->assertStringContainsString('elaboración', $exception->getMessage());
        }
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => 13000]);
    }

    // Flujo: la excepción de compensación no permite movimientos arbitrarios de ingredientes inactivos.
    public function test_inactive_ingredients_cannot_use_arbitrary_movements_or_unlinked_compensation(): void
    {
        // Preparación: elabora y desactiva un ingrediente sin crear una cancelación.
        $data = $this->fixture();
        $data['actor']->givePermissionTo(Permission::findOrCreate('inventory.move', 'web'));
        $created = $this->manufacture($data)->assertCreated();
        $original = FeedStockOperation::query()->findOrFail($created->json('data.id'));
        $data['products'][0]->forceFill(['status' => ProductStatus::Inactive])->save();
        $command = new InventoryMovementCommand(
            type: InventoryMovementType::Reversal,
            lines: [['product_id' => $data['products'][0]->id, 'stock_location_id' => $data['balances'][0][0]->stock_location_id, 'on_hand_delta' => '1']],
            operationId: (string) Str::uuid(), referenceType: 'feed_manufacture_cancellation',
            referenceId: (string) $original->id, reversesMovementId: $original->inventory_movement_id,
        );

        // Mutación: ni un movimiento genérico ni una referencia original habilitan devolución arbitraria.
        foreach ([null, $original] as $compensation) {
            try {
                app(RecordInventoryMovementAction::class)->execute($command, $data['actor'], 'api', $compensation);
                $this->fail('Debió rechazar el movimiento arbitrario de ingrediente inactivo.');
            } catch (InventoryConflict) {
                $this->assertDatabaseCount('inventory_movements', 1);
            }
        }
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertSame(ProductStatus::Inactive, $data['products'][0]->fresh()->status);
    }

    // Flujo: todas las consultas y mutaciones requieren autenticación y filtros acotados.
    public function test_anonymous_endpoints_return_401_and_authenticated_filters_are_bounded(): void
    {
        // Requests: consulta y modifica sin una sesión autenticada.
        foreach (['/elaboraciones', '/saldos-alimento', '/ajustes-alimentacion'] as $path) {
            $this->getJson('/api/v1'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/elaboraciones', [], $this->headers())->assertUnauthorized();

        // Preparación: un usuario sin permisos específicos mantiene el acceso provisional acordado.
        $this->fixture();

        // Requests: rechaza paginación sin límites y campos inesperados.
        $this->getJson('/api/v1/elaboraciones?por_pagina=101')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        $this->getJson('/api/v1/saldos-alimento?up_id=0')->assertUnprocessable()->assertJsonValidationErrors('up_id');
        $this->getJson('/api/v1/ajustes-alimentacion?tipo=otro')->assertUnprocessable()->assertJsonValidationErrors('tipo');
    }

    /** @return array{actor:User, recipe:FeedRecipe, products:list<Product>, plants:list<PoultryHouse>, balances:list<list<StockBalance>>} */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        Sanctum::actingAs($actor);
        $recipe = FeedRecipe::factory()->create(['created_by' => $actor->id]);
        $products = Product::factory()->rawMaterial()->count(2)->create()->all();
        foreach ([10000, 2000] as $index => $amount) {
            FeedRecipeIngredient::factory()->create(['feed_recipe_id' => $recipe->id, 'product_id' => $products[$index]->id, 'quantity_grams' => $amount]);
        }
        $first = PoultryHouse::factory()->feed()->create();
        $plants = [$first, PoultryHouse::factory()->feed()->create(['production_unit_id' => $first->production_unit_id]), PoultryHouse::factory()->feed()->create(['production_unit_id' => $first->production_unit_id])];
        $balances = [];
        foreach ([[2000, 500], [3000, 8000], [8000, 1000]] as $plantIndex => $amounts) {
            $location = $this->location($plants[$plantIndex]);
            foreach ($amounts as $productIndex => $amount) {
                $balances[$plantIndex][] = StockBalance::factory()->create(['product_id' => $products[$productIndex]->id,
                    'stock_location_id' => $location->id, 'on_hand_quantity' => (string) $amount, 'allow_negative' => true]);
            }
        }

        return compact('actor', 'recipe', 'products', 'plants', 'balances');
    }

    private function location(PoultryHouse $plant): StockLocation
    {
        return StockLocation::factory()->create(['production_unit_id' => $plant->production_unit_id, 'poultry_house_id' => $plant->id, 'system_managed' => true]);
    }

    /** @param array<string, mixed> $data */
    private function manufacture(array $data, int $quantity = 13000): TestResponse
    {
        return $this->postJson('/api/v1/elaboraciones', ['receta_id' => $data['recipe']->id, 'planta_id' => $data['plants'][0]->id, 'cantidad_g' => $quantity], $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
