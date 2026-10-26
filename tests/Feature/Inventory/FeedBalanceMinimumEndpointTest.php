<?php

namespace Tests\Feature\Inventory;

use App\Enums\Inventory\FeedBalanceStatus;
use App\Events\Inventory\FeedBalanceEvaluated;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FeedBalanceMinimumEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: configura y quita mínimos sin movimientos, conservando el saldo cero y la auditoría.
    public function test_configures_minimum_before_any_movement_and_can_clear_it(): void
    {
        // Preparación: crea receta y UP sin una cuenta materializada.
        $actor = $this->signIn();
        $recipe = FeedRecipe::factory()->create();
        $unit = ProductionUnit::factory()->create();
        $url = $this->url($recipe, $unit);
        Event::fake([FeedBalanceEvaluated::class]);

        // Consulta: el saldo virtual permanece en cero y todavía no tiene mínimo.
        $this->getJson(str_replace('/minimo', '/saldo', $url))->assertOk()
            ->assertJsonPath('data.saldo_g', '0')->assertJsonPath('data.minimo_g', null)
            ->assertJsonPath('data.estado_stock', 'sin_configurar')->assertJsonPath('data.requiere_atencion', false);
        $this->assertDatabaseCount('feed_balances', 0);

        // Request: crea únicamente la cuenta necesaria para almacenar el mínimo.
        $this->patchJson($url, ['minimo_g' => '300000'])->assertOk()
            ->assertJsonPath('data.saldo_g', '0')->assertJsonPath('data.minimo_g', '300000')
            ->assertJsonPath('data.estado_stock', 'por_debajo')->assertJsonPath('data.requiere_atencion', true);
        $this->assertDatabaseHas('feed_balances', ['feed_recipe_id' => $recipe->id, 'production_unit_id' => $unit->id,
            'quantity_grams' => 0, 'minimum_quantity_grams' => 300000]);

        // Requests: cero es un mínimo válido y null vuelve a dejarlo sin configurar.
        $this->patchJson($url, ['minimo_g' => 0])->assertOk()->assertJsonPath('data.estado_stock', 'en_minimo');
        $this->patchJson($url, ['minimo_g' => null])->assertOk()
            ->assertJsonPath('data.minimo_g', null)->assertJsonPath('data.estado_stock', 'sin_configurar');

        // Verificación: registra cambios permitidos con actor y UP sin fabricar movimientos de stock.
        $entries = AuditEntry::query()->where('event', 'feed_balance_minimum_changed')->orderBy('id')->get();
        $this->assertCount(3, $entries);
        $this->assertSame($actor->id, $entries[0]->causer_id);
        $this->assertSame($unit->id, $entries[0]->up_id);
        $this->assertSame(['minimum_quantity_grams' => null], $entries[0]->attribute_changes['old']);
        $this->assertSame(['minimum_quantity_grams' => '300000'], $entries[0]->attribute_changes['new']);
        $this->assertSame('0', $entries[0]->properties->get('subject_snapshot')['quantity_grams']);
        $this->assertDatabaseCount('feed_balances', 1);
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('feed_consumption_movements', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        Event::assertDispatched(FeedBalanceEvaluated::class, 3);
        Event::assertDispatched(FeedBalanceEvaluated::class, fn (FeedBalanceEvaluated $event): bool => $event->operationId === $entries[0]->operation_id && $event->reason === 'minimum_changed'
            && $event->status === FeedBalanceStatus::BelowMinimum && $event->quantityGrams === 0);
    }

    // Flujo: cada receta y UP conserva su configuración incluso con saldos negativos o recetas inactivas.
    public function test_minimum_updates_preserve_quantities_and_other_recipe_unit_accounts(): void
    {
        // Preparación: materializa tres cuentas independientes con mínimos diferentes.
        $this->signIn();
        $recipe = FeedRecipe::factory()->inactive()->create();
        $unit = ProductionUnit::factory()->create();
        $target = FeedBalance::factory()->create(['feed_recipe_id' => $recipe->id, 'production_unit_id' => $unit->id,
            'quantity_grams' => -10, 'minimum_quantity_grams' => 50]);
        $otherRecipe = FeedBalance::factory()->create(['production_unit_id' => $unit->id, 'minimum_quantity_grams' => 75]);
        $otherUnit = FeedBalance::factory()->create(['feed_recipe_id' => $recipe->id, 'minimum_quantity_grams' => 100]);

        // Request: configura el máximo permitido en la cuenta de receta inactiva.
        $this->patchJson($this->url($recipe, $unit), ['minimo_g' => '999999999999'])->assertOk()
            ->assertJsonPath('data.minimo_g', '999999999999')->assertJsonPath('data.saldo_g', '-10');

        // Consulta: los saldos y configuraciones ajenos permanecen intactos.
        $this->assertSame(-10, $target->fresh()->quantity_grams);
        $this->assertSame(75, $otherRecipe->fresh()->minimum_quantity_grams);
        $this->assertSame(100, $otherUnit->fresh()->minimum_quantity_grams);
    }

    // Flujo: las consultas puntual y paginada exponen los cuatro estados usando gramos enteros exactos.
    #[DataProvider('stockStates')]
    public function test_balance_queries_expose_stock_status(int $quantity, ?int $minimum, string $status, bool $attention): void
    {
        // Preparación: materializa la cuenta del caso y permite la lectura autenticada existente.
        $this->signIn(false);
        $balance = FeedBalance::factory()->create(['quantity_grams' => $quantity, 'minimum_quantity_grams' => $minimum]);

        // Consultas: el listado y el detalle calculan exactamente el mismo estado.
        $expectedMinimum = $minimum === null ? null : (string) $minimum;
        $this->getJson('/api/v1/saldos-alimento')->assertOk()
            ->assertJsonPath('data.0.minimo_g', $expectedMinimum)->assertJsonPath('data.0.estado_stock', $status)
            ->assertJsonPath('data.0.requiere_atencion', $attention);
        $this->getJson('/api/v1/unidades-productivas/'.$balance->production_unit_id.'/recetas/'.$balance->feed_recipe_id.'/saldo')->assertOk()
            ->assertJsonPath('data.minimo_g', $expectedMinimum)->assertJsonPath('data.estado_stock', $status)
            ->assertJsonPath('data.requiere_atencion', $attention);
    }

    /** @return array<string, array{int, ?int, string, bool}> */
    public static function stockStates(): array
    {
        return [
            'sin configurar' => [800000, null, 'sin_configurar', false],
            'negativo sin configurar' => [-1, null, 'sin_configurar', false],
            'suficiente' => [800000, 300000, 'suficiente', false],
            'en mínimo' => [250000, 250000, 'en_minimo', true],
            'por debajo' => [200000, 250000, 'por_debajo', true],
            'cero configurado' => [0, 0, 'en_minimo', true],
            'negativo con mínimo' => [-1, 0, 'por_debajo', true],
        ];
    }

    // Flujo: el filtro de atención incluye igualdad, respeta la UP y pagina antes de responder.
    public function test_attention_filter_is_scoped_and_paginates_matching_balances(): void
    {
        // Preparación: combina cuentas suficientes, sin configurar, en mínimo y por debajo en dos UP.
        $this->signIn(false);
        $unit = ProductionUnit::factory()->create(['name' => 'San José']);
        $starter = FeedBalance::factory()->create(['production_unit_id' => $unit->id, 'quantity_grams' => 800000, 'minimum_quantity_grams' => 300000]);
        $layer = FeedBalance::factory()->create(['production_unit_id' => $unit->id, 'quantity_grams' => 200000, 'minimum_quantity_grams' => 250000]);
        FeedBalance::factory()->create(['production_unit_id' => $unit->id, 'quantity_grams' => 0, 'minimum_quantity_grams' => 0]);
        FeedBalance::factory()->create(['production_unit_id' => $unit->id, 'quantity_grams' => -1]);
        FeedBalance::factory()->create(['quantity_grams' => 1000, 'minimum_quantity_grams' => 1000]);
        FeedBalance::factory()->create(['quantity_grams' => 1000, 'minimum_quantity_grams' => 0]);

        // Consultas: atención incluye mínimo y por debajo; el complemento incluye suficiente y sin configurar.
        $this->getJson('/api/v1/saldos-alimento?requiere_atencion=1&up_id='.$unit->id.'&por_pagina=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado_stock', 'en_minimo');
        $this->getJson('/api/v1/saldos-alimento?requiere_atencion=1&up_id='.$unit->id.'&receta_id='.$layer->feed_recipe_id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.saldo_g', '200000');
        $this->getJson('/api/v1/saldos-alimento?requiere_atencion=0&up_id='.$unit->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/saldos-alimento?requiere_atencion=1&receta_id='.$starter->feed_recipe_id)->assertOk()->assertJsonCount(0, 'data');
    }

    // Flujo: rechaza valores inválidos y campos capaces de alterar el stock sin dejar efectos.
    #[DataProvider('invalidMinimums')]
    public function test_invalid_minimum_returns_422_without_materializing_a_balance(array $payload, string $field, string $message): void
    {
        // Preparación: dispone de permiso de configuración, pero todavía no existe saldo.
        $this->signIn();
        $recipe = FeedRecipe::factory()->create();
        $unit = ProductionUnit::factory()->create();

        // Request: valida el payload antes de crear cuentas o auditar.
        $this->json('PATCH', $this->url($recipe, $unit), $payload, [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('feed_balances', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidMinimums(): array
    {
        $invalidGrams = 'El mínimo debe expresarse en gramos enteros entre 0 y 999999999999.';

        return [
            'ausente' => [[], 'minimo_g', 'Debes indicar el mínimo en gramos o null para quitarlo.'],
            'negativo' => [['minimo_g' => -1], 'minimo_g', 'El mínimo no puede ser negativo.'],
            'fracción' => [['minimo_g' => '1.5'], 'minimo_g', $invalidGrams],
            'float' => [['minimo_g' => 1.0], 'minimo_g', $invalidGrams],
            'booleano' => [['minimo_g' => true], 'minimo_g', $invalidGrams],
            'límite' => [['minimo_g' => '1000000000000'], 'minimo_g', $invalidGrams],
            'científica' => [['minimo_g' => '1e3'], 'minimo_g', $invalidGrams],
            'array' => [['minimo_g' => [1]], 'minimo_g', $invalidGrams],
            'campo ajeno' => [['minimo_g' => 10, 'saldo_g' => 100], 'saldo_g', 'El campo no está permitido en esta operación.'],
        ];
    }

    // Flujo: exige autenticación y permiso de gestión de Inventario sin asignaciones por UP.
    public function test_minimum_configuration_requires_authentication_and_inventory_management_permission(): void
    {
        // Preparación: crea los destinos antes de enviar peticiones anónimas y de sólo lectura.
        $recipe = FeedRecipe::factory()->create();
        $unit = ProductionUnit::factory()->create();

        // Requests: rechaza la falta de sesión y luego la falta de permiso funcional.
        $this->patchJson($this->url($recipe, $unit), ['minimo_g' => 10])->assertUnauthorized();
        $this->signIn(false);
        $this->patchJson($this->url($recipe, $unit), ['minimo_g' => 10])->assertForbidden();
        $this->assertDatabaseCount('feed_balances', 0);
    }

    // Flujo: rechaza filtros inválidos y entidades inexistentes sin crear saldos técnicos huérfanos.
    public function test_invalid_filters_and_missing_targets_do_not_change_stock(): void
    {
        // Preparación: autentica un gestor con una receta y una UP existentes.
        $this->signIn();
        $recipe = FeedRecipe::factory()->create();
        $unit = ProductionUnit::factory()->create();

        // Requests: valida filtros acotados y los dos parámetros del recurso.
        $this->getJson('/api/v1/saldos-alimento?requiere_atencion=otro')->assertUnprocessable()->assertJsonValidationErrors('requiere_atencion');
        $this->patchJson('/api/v1/unidades-productivas/999999/recetas/'.$recipe->id.'/minimo', ['minimo_g' => 1])->assertNotFound();
        $this->patchJson('/api/v1/unidades-productivas/'.$unit->id.'/recetas/999999/minimo', ['minimo_g' => 1])->assertNotFound();
        $this->assertDatabaseCount('feed_balances', 0);
    }

    // Flujo: una falla de auditoría revierte incluso la materialización inicial del saldo y no publica evaluación.
    public function test_audit_failure_rolls_back_minimum_and_suppresses_evaluation(): void
    {
        // Preparación: simula una falla del contrato de auditoría dentro de la transacción.
        $this->signIn();
        $recipe = FeedRecipe::factory()->create();
        $unit = ProductionUnit::factory()->create();
        Event::fake([FeedBalanceEvaluated::class]);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Auditoría no disponible'));

        // Request: el error no conserva el mínimo ni una cuenta parcialmente creada.
        $this->patchJson($this->url($recipe, $unit), ['minimo_g' => 100])->assertInternalServerError();
        $this->assertDatabaseCount('feed_balances', 0);
        Event::assertNotDispatched(FeedBalanceEvaluated::class);
    }

    private function signIn(bool $manage = true): User
    {
        $actor = User::factory()->create();
        if ($manage) {
            $actor->givePermissionTo(Permission::findOrCreate('inventory.manage', 'web'));
        }
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function url(FeedRecipe $recipe, ProductionUnit $unit): string
    {
        return '/api/v1/unidades-productivas/'.$unit->id.'/recetas/'.$recipe->id.'/minimo';
    }
}
