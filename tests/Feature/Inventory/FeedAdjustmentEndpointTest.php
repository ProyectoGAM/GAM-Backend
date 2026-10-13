<?php

namespace Tests\Feature\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class FeedAdjustmentEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: alimento admite saldo negativo por UP y los ajustes no consumen ni devuelven ingredientes.
    public function test_feed_adjustments_are_independent_and_allow_zero_negative_balances_per_up(): void
    {
        // Preparación: crea una cuenta de ingredientes sin usarla como fuente de alimento.
        $data = $this->fixture();
        $url = $this->feedUrl($data);

        // Consulta: una cuenta todavía inexistente tiene saldo cero sin materializar filas.
        $this->getJson(str_replace('/ajustes', '/saldo', $url))->assertOk()->assertJsonPath('data.saldo_g', '0');
        $this->assertDatabaseCount('feed_balances', 0);

        // Requests: establece, suma, resta y vuelve a cero el alimento.
        $this->postJson($url, ['accion' => 'establecer', 'valor_g' => '-100', 'motivo' => 'Recuento'], $this->headers())
            ->assertCreated()->assertJsonPath('data.saldo_resultante_g', '-100');
        $this->postJson($url, ['accion' => 'sumar', 'valor_g' => 50, 'motivo' => 'Corrección'], $this->headers())
            ->assertCreated()->assertJsonPath('data.saldo_resultante_g', '-50');
        $this->postJson($url, ['accion' => 'restar', 'valor_g' => 20, 'motivo' => 'Merma'], $this->headers())
            ->assertCreated()->assertJsonPath('data.saldo_resultante_g', '-70');
        $this->postJson($url, ['accion' => 'establecer', 'valor_g' => 0, 'motivo' => 'Recuento final'], $this->headers())
            ->assertCreated()->assertJsonPath('data.saldo_resultante_g', '0');

        // Request: la misma receta mantiene otra cuenta independiente en otra UP.
        $other = ProductionUnit::factory()->create();
        $this->postJson('/api/v1/unidades-productivas/'.$other->id.'/recetas/'.$data['recipe']->id.'/ajustes',
            ['accion' => 'sumar', 'valor_g' => 10, 'motivo' => 'Corrección en otra UP'], $this->headers())->assertCreated();

        // Consulta: los ingredientes y movimientos de inventario permanecen independientes.
        $this->assertSame('100.500000', $data['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('feed_balances', 2);
        $this->getJson('/api/v1/ajustes-alimentacion?tipo=alimento&up_id='.$data['plant']->production_unit_id.'&por_pagina=2')
            ->assertOk()->assertJsonPath('meta.total', 4)->assertJsonCount(2, 'data')->assertJsonPath('data.0.motivo', 'Recuento final');
        $entry = AuditEntry::query()->where('event', 'feed_feed_adjustment_recorded')->orderBy('id')->firstOrFail();
        $this->assertSame($data['actor']->id, $entry->causer_id);
        $this->assertSame($data['plant']->production_unit_id, $entry->up_id);
        $this->assertSame('-100.000000', $entry->attribute_changes['new']['quantity_grams']);
    }

    // Flujo: ajustes enteros preservan la precisión existente y establecer elimina una fracción exactamente.
    public function test_ingredient_adjustments_preserve_precision_and_never_modify_feed(): void
    {
        // Preparación: el saldo existente contiene medios gramos.
        $data = $this->fixture();
        $url = $this->ingredientUrl($data);

        // Requests: suma, resta y establece un saldo entero sin redondeos.
        $this->postJson($url, ['accion' => 'sumar', 'valor_g' => 10, 'motivo' => 'Recuento'], $this->headers())
            ->assertCreated()->assertJsonPath('data.saldo_resultante_g', '110.500000');
        $this->postJson($url, ['accion' => 'restar', 'valor_g' => 100, 'motivo' => 'Merma'], $this->headers())
            ->assertCreated()->assertJsonPath('data.saldo_resultante_g', '10.500000');
        $this->postJson($url, ['accion' => 'establecer', 'valor_g' => 0, 'motivo' => 'Vacío'], $this->headers())
            ->assertCreated()->assertJsonPath('data.delta_g', '-10.500000');
        $this->assertSame('0.000000', $data['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseCount('feed_balances', 0);
        $this->getJson('/api/v1/ajustes-alimentacion?tipo=ingrediente&producto_id='.$data['product']->id)
            ->assertOk()->assertJsonPath('meta.total', 3);
    }

    // Flujo: un ajuste sin diferencia sigue guardando motivo e historia pero no fabrica líneas nulas.
    public function test_no_change_adjustment_is_audited_once_and_replay_conflicts_across_operation_types(): void
    {
        // Preparación: utiliza el mismo saldo entero y una única clave.
        $data = $this->fixture();
        $data['balance']->forceFill(['on_hand_quantity' => 100])->save();
        $payload = ['accion' => 'establecer', 'valor_g' => '0100', 'motivo' => 'Recuento confirmado'];
        $headers = $this->headers();

        // Requests: registra y reintenta el recuento sin delta, luego reutiliza la clave en otro tipo.
        $first = $this->postJson($this->ingredientUrl($data), $payload, $headers)->assertCreated();
        $payload['valor_g'] = 100;
        $this->postJson($this->ingredientUrl($data), $payload, $headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($this->feedUrl($data), $payload, $headers)->assertConflict();
        $payload['motivo'] = 'Otro recuento';
        $this->postJson($this->ingredientUrl($data), $payload, $headers)->assertConflict();
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(1, AuditEntry::query()->where('event', 'feed_ingredient_adjustment_recorded')->count());
    }

    // Flujo: sumar/restar requieren positivos y establecer alimento admite enteros con signo.
    #[DataProvider('invalidAdjustmentValues')]
    public function test_returns_422_for_invalid_adjustment_values(string $mode, mixed $value, bool $ingredient): void
    {
        // Preparación: elige el destino indicado por el caso.
        $data = $this->fixture();
        $url = $ingredient ? $this->ingredientUrl($data) : $this->feedUrl($data);

        // Request: valida el valor sin alterar saldos o historial.
        $this->json('POST', $url, ['accion' => $mode, 'valor_g' => $value, 'motivo' => 'Recuento'], $this->headers(), JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('valor_g');
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /** @return array<string, array{string,mixed,bool}> */
    public static function invalidAdjustmentValues(): array
    {
        return [
            'sumar cero' => ['sumar', 0, false], 'restar cero' => ['restar', 0, true],
            'sumar negativo' => ['sumar', -1, true], 'restar negativo' => ['restar', -1, false],
            'establecer ingrediente negativo' => ['establecer', -1, true], 'float' => ['establecer', 100.0, false],
            'científica' => ['sumar', '1e2', true], 'límite' => ['establecer', '1000000000000', false],
        ];
    }

    // Flujo: motivo y acción son obligatorios, y la clave se acepta sólo en el encabezado.
    public function test_returns_422_for_missing_reason_invalid_mode_or_body_key(): void
    {
        // Preparación: conserva una cuenta válida.
        $data = $this->fixture();

        // Requests: rechaza las tres entradas sin efectos.
        $this->postJson($this->feedUrl($data), ['accion' => 'sumar', 'valor_g' => 10], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('motivo')->assertJsonPath('errors.motivo.0', 'Debes indicar el motivo.');
        $this->postJson($this->ingredientUrl($data), ['accion' => 'otra', 'valor_g' => 10, 'motivo' => 'Recuento'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('accion');
        $this->postJson($this->feedUrl($data), ['accion' => 'sumar', 'valor_g' => 10, 'motivo' => 'Recuento', 'idempotency_key' => (string) Str::uuid()], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('feed_stock_operations', 0);
    }

    // Flujo: los ingredientes requieren planta operativa y producto activo, y no pueden terminar negativos.
    public function test_returns_409_for_negative_result_inactive_ingredient_or_non_operational_plant(): void
    {
        // Preparación: conserva un saldo menor al descuento propuesto.
        $data = $this->fixture();
        $payload = ['accion' => 'restar', 'valor_g' => 101, 'motivo' => 'Merma'];

        // Request: rechaza el resultado negativo.
        $this->postJson($this->ingredientUrl($data), $payload, $this->headers())->assertConflict();

        // Mutaciones y requests: también rechaza las entidades no habilitadas.
        $data['product']->forceFill(['status' => ProductStatus::Inactive])->save();
        $payload = ['accion' => 'sumar', 'valor_g' => 1, 'motivo' => 'Recuento'];
        $this->postJson($this->ingredientUrl($data), $payload, $this->headers())->assertConflict();
        $data['product']->forceFill(['status' => ProductStatus::Active])->save();
        $data['plant']->forceFill(['status' => PoultryHouseStatus::OutOfService])->save();
        $this->postJson($this->ingredientUrl($data), $payload, $this->headers())->assertConflict();
        $data['recipe']->forceFill(['status' => FeedRecipeStatus::Inactive])->save();
        $this->postJson($this->feedUrl($data), $payload, $this->headers())->assertConflict();
        $this->assertSame('100.500000', $data['balance']->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: limita los saldos acumulados y permite un delta alimentario mayor al límite individual.
    public function test_balance_and_delta_limits_are_checked_without_overflow(): void
    {
        // Preparación: coloca alimento e ingrediente en su máximo persistible.
        $data = $this->fixture();
        FeedBalance::factory()->create(['feed_recipe_id' => $data['recipe']->id, 'production_unit_id' => $data['plant']->production_unit_id, 'quantity_grams' => 999999999999]);
        $data['balance']->forceFill(['on_hand_quantity' => '999999999999.999999'])->save();

        // Requests: sumar uno excede la persistencia y se revierte.
        $payload = ['accion' => 'sumar', 'valor_g' => 1, 'motivo' => 'Recuento'];
        $this->postJson($this->feedUrl($data), $payload, $this->headers())->assertConflict();
        $this->postJson($this->ingredientUrl($data), $payload, $this->headers())->assertConflict();

        // Request: establecer el extremo negativo de alimento conserva exactamente el delta completo.
        $this->postJson($this->feedUrl($data), ['accion' => 'establecer', 'valor_g' => '-999999999999', 'motivo' => 'Recuento'], $this->headers())
            ->assertCreated()->assertJsonPath('data.delta_g', '-1999999999998');
        $this->assertDatabaseCount('feed_stock_operations', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: la auditoría del ajuste comparte transacción con el movimiento y el saldo.
    public function test_ingredient_adjustment_audit_failure_rolls_back_movement_and_balance(): void
    {
        // Preparación: simula una falla del grabador después de registrar el movimiento.
        $data = $this->fixture();
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                if ($entry->event === 'feed_ingredient_adjustment_recorded') {
                    throw new RuntimeException('Falla de auditoría de ajuste');
                }
            }
        });

        // Request: el error no deja un movimiento o ajuste parcial.
        $this->postJson($this->ingredientUrl($data), ['accion' => 'sumar', 'valor_g' => 1, 'motivo' => 'Recuento'], $this->headers())->assertServerError();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertSame('100.500000', $data['balance']->fresh()->on_hand_quantity);
    }

    /** @return array{actor:User, recipe:FeedRecipe, plant:PoultryHouse, product:Product, balance:StockBalance} */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        Sanctum::actingAs($actor);
        $recipe = FeedRecipe::factory()->create(['created_by' => $actor->id]);
        $plant = PoultryHouse::factory()->feed()->create();
        $product = Product::factory()->rawMaterial()->create();
        $location = StockLocation::factory()->create(['production_unit_id' => $plant->production_unit_id, 'poultry_house_id' => $plant->id, 'system_managed' => true]);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'stock_location_id' => $location->id, 'on_hand_quantity' => '100.500000', 'allow_negative' => true]);

        return compact('actor', 'recipe', 'plant', 'product', 'balance');
    }

    /** @param array<string, mixed> $data */
    private function ingredientUrl(array $data): string
    {
        return '/api/v1/plantas-racion/'.$data['plant']->id.'/ingredientes/'.$data['product']->id.'/ajustes';
    }

    /** @param array<string, mixed> $data */
    private function feedUrl(array $data): string
    {
        return '/api/v1/unidades-productivas/'.$data['plant']->production_unit_id.'/recetas/'.$data['recipe']->id.'/ajustes';
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
