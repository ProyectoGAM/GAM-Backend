<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateFeedRecipeAction;
use App\Actions\Inventory\UpdateFeedRecipeQuantitiesAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FeedRecipeEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea recetas globales con nombres repetidos, ingredientes existentes y rendimiento independiente.
    public function test_creates_global_recipes_without_creating_products_or_requiring_equal_yield(): void
    {
        // Preparación: crea el catálogo y autentica sólo con el permiso de recetas.
        $actor = $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $productCount = Product::query()->count();

        // Acción: registra dos recetas de igual nombre con operaciones distintas.
        $first = $this->postJson('/api/v1/recetas', [...$payload, 'rendimiento_g' => '14000'], $this->headers())->assertCreated();
        $second = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated();
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $first->assertJsonPath('data.rendimiento_g', '14000')->assertJsonPath('data.estado', 'active');

        // Consulta: verifica el catálogo global y la instantánea de auditoría.
        $this->getJson('/api/v1/recetas')->assertOk()->assertJsonPath('meta.total', 2);
        $entry = AuditEntry::query()->where('subject_id', $first->json('data.id'))->where('event', 'feed_recipe_created')->sole();
        $this->assertSame($actor->id, $entry->causer_id);
        $this->assertSame($first->headers->get('X-Trace-Id'), $entry->trace_id);
        $this->assertSame(FeedRecipe::class, $entry->subject_type);
        $this->assertSame('inventory', $entry->log_name);
        $this->assertNull($entry->up_id);
        $this->assertTrue(Str::isUuid($entry->operation_id));
        $this->assertSame('14000', $entry->properties->get('subject_snapshot')['yield_grams']);
        $this->assertSame($productCount, Product::query()->count());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    // Flujo: reintenta un alta aun después de editarla sin recrear ni sobrescribir la receta.
    public function test_creation_replay_is_canonical_and_does_not_restore_old_quantities(): void
    {
        // Preparación: conserva la clave y el contenido originales.
        $this->signIn(['feed-recipes.manage']);
        $payload = $this->payload();
        $headers = $this->headers();
        $first = $this->postJson('/api/v1/recetas', $payload, $headers)->assertCreated();
        $id = $first->json('data.id');
        $updated = $payload['ingredientes'];
        $updated[0]['cantidad_g'] = '2500';

        // Acción: edita y repite el alta original con ingredientes en distinto orden.
        $this->patchJson("/api/v1/recetas/$id/cantidades", ['ingredientes' => $updated])->assertOk();
        $replayPayload = $payload;
        $replayPayload['ingredientes'] = array_reverse($payload['ingredientes']);
        $replay = $this->postJson('/api/v1/recetas', $replayPayload, $headers)->assertCreated();
        $replay->assertJsonPath('data.id', $id)->assertJsonPath('data.ingredientes.0.cantidad_g', '2500');

        // Acción: reutiliza la misma clave con otros datos.
        $this->postJson('/api/v1/recetas', [...$payload, 'nombre' => 'Otro nombre'], $headers)->assertConflict();
        $this->assertDatabaseCount('feed_recipes', 1);
        $this->assertSame(1, AuditEntry::query()->where('event', 'feed_recipe_created')->count());
    }

    // Flujo: acepta gramos exactos por encima del rango seguro de JavaScript sin redondearlos.
    public function test_large_integer_grams_are_preserved_as_strings(): void
    {
        // Preparación: define una combinación con números que requieren transporte exacto.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $payload['rendimiento_g'] = '9007199254740993';
        $payload['ingredientes'][0]['cantidad_g'] = '9223372036854775807';

        // Acción: crea y calcula el múltiplo base.
        $created = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated();
        $created->assertJsonPath('data.rendimiento_g', '9007199254740993');
        $this->postJson('/api/v1/recetas/'.$created->json('data.id').'/calculo', ['cantidad_g' => '9007199254740993'])
            ->assertOk()->assertJsonPath('data.ingredientes.0.cantidad_requerida_g', '9223372036854775807');
    }

    // Flujo: rechaza gramos fraccionarios, formatos aproximados, valores nulos y desbordamientos en todas las entradas.
    #[DataProvider('invalidGrams')]
    public function test_invalid_grams_return_422_without_persistence(mixed $quantity): void
    {
        // Preparación: conserva una receta válida para probar edición y cálculo.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $created = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated();
        $id = $created->json('data.id');
        $invalidIngredients = $payload['ingredientes'];
        $invalidIngredients[0]['cantidad_g'] = $quantity;

        // Acción: intenta cantidades inválidas en alta, rendimiento, edición y cálculo.
        $this->postJson('/api/v1/recetas', [...$payload, 'ingredientes' => $invalidIngredients], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('ingredientes.0.cantidad_g');
        $this->postJson('/api/v1/recetas', [...$payload, 'rendimiento_g' => $quantity], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('rendimiento_g');
        $this->patchJson("/api/v1/recetas/$id/cantidades", ['ingredientes' => $invalidIngredients])
            ->assertUnprocessable()->assertJsonValidationErrors('ingredientes.0.cantidad_g');
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => $quantity])
            ->assertUnprocessable()->assertJsonValidationErrors('cantidad_g');

        // Consulta: confirma que todos los rechazos conservaron datos y auditoría.
        $this->assertDatabaseCount('feed_recipes', 1);
        $this->assertDatabaseHas('feed_recipe_ingredients', ['feed_recipe_id' => $id, 'product_id' => $payload['ingredientes'][0]['producto_id'], 'quantity_grams' => 2000]);
        $this->assertDatabaseCount('activity_log', 1);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidGrams(): array
    {
        return [
            'zero' => [0], 'negative' => [-1], 'decimal number' => [1.5],
            'decimal string' => ['2000.0'], 'scientific string' => ['2e3'],
            'overflow' => ['9223372036854775808'], 'boolean' => [true], 'null' => [null],
            'array' => [[]], 'missing digits' => ['grams'],
        ];
    }

    // Flujo: exige materias primas existentes y distintas sin crear ingredientes desde recetas.
    public function test_invalid_or_duplicate_ingredients_return_422(): void
    {
        // Preparación: crea materias primas y un producto que no es ingrediente.
        $this->signIn(['feed-recipes.manage']);
        $payload = $this->payload();
        $supply = Product::factory()->create();

        // Acción: prueba referencias inexistentes, productos ajenos y duplicados.
        foreach ([999999, $supply->id, $payload['ingredientes'][1]['producto_id']] as $productId) {
            $ingredients = $payload['ingredientes'];
            $ingredients[0]['producto_id'] = $productId;
            $this->postJson('/api/v1/recetas', [...$payload, 'ingredientes' => $ingredients], $this->headers())
                ->assertUnprocessable()->assertJsonValidationErrors('ingredientes.0.producto_id');
        }
        $this->postJson('/api/v1/recetas', [...$payload, 'ingredientes' => []], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('ingredientes');
        $this->assertDatabaseCount('feed_recipes', 0);
        $this->assertDatabaseCount('products', 4);
    }

    // Flujo: edita sólo cantidades y conserva auditoría histórica y resultados calculados antes del cambio.
    public function test_quantity_updates_preserve_composition_and_append_history(): void
    {
        // Preparación: registra y calcula la receta original.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $created = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated();
        $id = $created->json('data.id');
        $originalAudit = AuditEntry::query()->where('event', 'feed_recipe_created')->sole()->toArray();
        $oldCalculation = $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 26000])->assertOk()->json('data');
        $ingredients = $payload['ingredientes'];
        $ingredients[0]['cantidad_g'] = 3000;

        // Acción: modifica la cantidad y repite la actualización sin nuevos efectos.
        $this->patchJson("/api/v1/recetas/$id/cantidades", ['ingredientes' => $ingredients])
            ->assertOk()->assertJsonPath('data.nombre', 'Ración BB')->assertJsonPath('data.nota', 'Receta de prueba')
            ->assertJsonPath('data.rendimiento_g', '13000')->assertJsonPath('data.ingredientes.0.cantidad_g', '3000');
        $this->patchJson("/api/v1/recetas/$id/cantidades", ['ingredientes' => $ingredients])->assertOk();

        // Consulta: verifica que sólo cambia la elaboración futura y la auditoría original queda intacta.
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 26000])
            ->assertOk()->assertJsonPath('data.ingredientes.0.cantidad_requerida_g', '6000');
        $this->assertSame('4000', $oldCalculation['ingredientes'][0]['cantidad_requerida_g']);
        $this->assertSame($originalAudit, AuditEntry::query()->findOrFail($originalAudit['id'])->toArray());
        $changed = AuditEntry::query()->where('event', 'feed_recipe_quantities_updated')->sole();
        $this->assertSame('2000', $changed->attribute_changes['old']['ingredients'][0]['quantity_grams']);
        $this->assertSame('3000', $changed->attribute_changes['new']['ingredients'][0]['quantity_grams']);
        $this->assertDatabaseCount('feed_recipe_ingredients', 3);
    }

    // Flujo: impide agregar, eliminar o sustituir ingredientes y modificar otros campos.
    public function test_composition_changes_and_extra_fields_are_rejected(): void
    {
        // Preparación: crea una receta y otra materia prima que no pertenece a ella.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');
        $extraProduct = Product::factory()->rawMaterial()->create();
        $replacement = $payload['ingredientes'];
        $replacement[0]['producto_id'] = $extraProduct->id;

        // Acción: intenta las tres alteraciones prohibidas de composición.
        foreach ([
            array_slice($payload['ingredientes'], 1),
            [...$payload['ingredientes'], ['producto_id' => $extraProduct->id, 'cantidad_g' => 1]],
            $replacement,
        ] as $ingredients) {
            $this->patchJson("/api/v1/recetas/$id/cantidades", ['ingredientes' => $ingredients])->assertConflict();
        }

        // Acción: intenta modificar cada campo que pertenece exclusivamente al alta o al estado.
        foreach (['nombre' => 'Cambio', 'nota' => 'Cambio', 'rendimiento_g' => 14000, 'estado' => 'inactive', 'production_unit_id' => 1] as $field => $value) {
            $this->patchJson("/api/v1/recetas/$id/cantidades", ['ingredientes' => $payload['ingredientes'], $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 26000, 'ingredientes' => $replacement])
            ->assertUnprocessable()->assertJsonValidationErrors('ingredientes');
        $this->postJson('/api/v1/recetas', [...$payload, 'ingredientes' => [['producto_id' => $extraProduct->id, 'cantidad_g' => 1, 'nombre' => 'Nuevo']]], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('ingredientes.0');
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseCount('feed_recipe_ingredients', 3);

        // Acción: confirma que no se publica ninguna eliminación física.
        $this->deleteJson("/api/v1/recetas/$id")->assertMethodNotAllowed();
    }

    // Flujo: conserva receta, composición e historial al desactivar y vuelve a habilitar el cálculo al reactivar.
    public function test_deactivation_and_reactivation_preserve_recipe_and_history(): void
    {
        // Preparación: crea una receta que permanecerá consultable.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $created = $this->postJson('/api/v1/recetas', $this->payload(), $this->headers())->assertCreated();
        $id = $created->json('data.id');
        $ingredients = $created->json('data.ingredientes');

        // Acción: desactiva dos veces y comprueba el bloqueo de nuevas elaboraciones.
        $this->patchJson("/api/v1/recetas/$id/estado", ['estado' => 'inactive'])->assertOk()->assertJsonPath('data.estado', 'inactive');
        $this->patchJson("/api/v1/recetas/$id/estado", ['estado' => 'inactive'])->assertOk();
        $this->getJson("/api/v1/recetas/$id")->assertOk()->assertJsonPath('data.ingredientes', $ingredients);
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 13000])->assertConflict();
        $this->getJson('/api/v1/recetas?estado=inactive&buscar=BB&por_pagina=1')->assertOk()->assertJsonPath('meta.total', 1);

        // Acción: reactiva y calcula la receta con el mismo identificador.
        $this->patchJson("/api/v1/recetas/$id/estado", ['estado' => 'active'])->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 13000])->assertOk();
        $this->assertSame(2, AuditEntry::query()->where('event', 'feed_recipe_status_changed')->count());
        $this->assertDatabaseCount('feed_recipes', 1);
        $this->assertDatabaseCount('feed_recipe_ingredients', 3);
    }

    // Flujo: calcula múltiplos exactos sin alterar saldos existentes, movimientos ni auditoría.
    #[DataProvider('multiples')]
    public function test_calculates_integer_multiples_without_stock_mutations(int $requested, string $factor, array $expected): void
    {
        // Preparación: crea una receta y stock fraccionario previo independiente de sus cantidades.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');
        $balance = StockBalance::factory()->create(['product_id' => $payload['ingredientes'][0]['producto_id'], 'on_hand_quantity' => '0.123456']);
        InventoryMovementLine::factory()->create(['product_id' => $balance->product_id, 'stock_location_id' => $balance->stock_location_id, 'unit' => 'g']);
        $balanceBefore = $balance->refresh()->toArray();
        $auditCount = AuditEntry::query()->count();

        // Acción: solicita el cálculo de un múltiplo sin autorización para mover inventario.
        $calculation = $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => $requested])->assertOk();
        $calculation->assertJsonPath('data.factor', $factor)->assertJsonPath('data.cantidad_g', (string) $requested);
        $this->assertSame($expected, array_column($calculation->json('data.ingredientes'), 'cantidad_requerida_g'));

        // Consulta: verifica que el cálculo no escribió en stock ni historial.
        $this->assertSame($balanceBefore, $balance->fresh()->toArray());
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_lines', 1);
        $this->assertSame($auditCount, AuditEntry::query()->count());
    }

    /** @return array<string, array{int, string, list<string>}> */
    public static function multiples(): array
    {
        return [
            'base' => [13000, '1', ['2000', '5000', '6000']],
            'double' => [26000, '2', ['4000', '10000', '12000']],
            'triple' => [39000, '3', ['6000', '15000', '18000']],
        ];
    }

    // Flujo: rechaza factores fraccionarios y multiplicaciones fuera del rango representable.
    public function test_fractional_factors_and_ingredient_overflow_return_422(): void
    {
        // Preparación: registra una receta normal y otra válida cuyo doble desborda.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');
        $payload['rendimiento_g'] = 1;
        $payload['ingredientes'][0]['cantidad_g'] = (string) PHP_INT_MAX;
        $largeId = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');

        // Acción: intenta producir 1,5 combinaciones y un resultado fuera de rango.
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 19500])
            ->assertUnprocessable()->assertJsonPath('errors.cantidad_g.0', 'La cantidad solicitada debe ser un múltiplo entero positivo del rendimiento base.');
        $this->postJson("/api/v1/recetas/$largeId/calculo", ['cantidad_g' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('cantidad_g');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // Flujo: aplica autenticación y permisos funcionales en cada ruta, sin asignaciones por UP.
    public function test_all_endpoints_enforce_authentication_and_functional_permissions(): void
    {
        // Preparación: crea una receta por factory para probar las fronteras de autorización.
        $recipe = FeedRecipe::factory()->has(FeedRecipeIngredient::factory(), 'ingredients')->create();
        $id = $recipe->id;
        $payload = ['nombre' => 'Otra', 'rendimiento_g' => 1, 'ingredientes' => [['producto_id' => $recipe->ingredients->first()->product_id, 'cantidad_g' => 1]]];
        $requests = [
            ['GET', '/api/v1/recetas', []], ['GET', "/api/v1/recetas/$id", []],
            ['POST', '/api/v1/recetas', $payload],
            ['PATCH', "/api/v1/recetas/$id/cantidades", ['ingredientes' => $payload['ingredientes']]],
            ['PATCH', "/api/v1/recetas/$id/estado", ['estado' => 'inactive']],
            ['POST', "/api/v1/recetas/$id/calculo", ['cantidad_g' => 13000]],
        ];

        // Acción: intenta todos los endpoints sin sesión y luego sin permisos funcionales.
        foreach ($requests as [$method, $path, $data]) {
            $this->json($method, $path, $data, $this->headers())->assertUnauthorized();
        }
        $this->signIn([]);
        foreach ($requests as [$method, $path, $data]) {
            $this->json($method, $path, $data, $this->headers())->assertForbidden();
        }

        // Acción: concede lectura a otro usuario y comprueba que no permite mutaciones.
        $this->signIn(['feed-recipes.view']);
        $this->getJson("/api/v1/recetas/$id")->assertOk();
        $this->postJson("/api/v1/recetas/$id/calculo", ['cantidad_g' => 13000])->assertOk();
        $this->patchJson("/api/v1/recetas/$id/estado", ['estado' => 'inactive'])->assertForbidden();
        $this->assertDatabaseHas('feed_recipes', ['id' => $id, 'status' => 'active']);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: valida encabezados, filtros acotados y estados sin aceptar campos internos.
    public function test_headers_filters_status_and_unknown_fields_are_validated(): void
    {
        // Preparación: autentica y conserva una receta válida.
        $this->signIn(['feed-recipes.manage', 'feed-recipes.view']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');

        // Acción: intenta omitir o enviar incorrectamente la clave de idempotencia.
        $this->postJson('/api/v1/recetas', $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/recetas', $payload, ['Idempotency-Key' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/v1/recetas', [...$payload, 'idempotency_key' => (string) Str::uuid()], $this->headers())->assertUnprocessable();

        // Consulta: comprueba filtros acotados, estado inválido y registros inexistentes.
        $this->getJson('/api/v1/recetas?por_pagina=101')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        $this->getJson('/api/v1/recetas?estado=deleted')->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->getJson('/api/v1/recetas?production_unit_id=1')->assertUnprocessable()->assertJsonValidationErrors('production_unit_id');
        $this->patchJson("/api/v1/recetas/$id/estado", ['estado' => 'deleted'])->assertUnprocessable();
        $this->getJson('/api/v1/recetas/999999')->assertNotFound();
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: revierte alta, edición y cambio de estado si no puede escribirse la auditoría.
    public function test_audit_failure_rolls_back_every_mutation(): void
    {
        // Preparación: registra una receta y sustituye la auditoría por un fallo controlado.
        $this->signIn(['feed-recipes.manage']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                throw new RuntimeException('Fallo controlado de auditoría');
            }
        });
        $this->withoutExceptionHandling();
        $ingredients = $payload['ingredientes'];
        $ingredients[0]['cantidad_g'] = 2500;

        // Acción: fuerza el fallo de auditoría en las tres mutaciones.
        foreach ([
            ['POST', '/api/v1/recetas', $payload],
            ['PATCH', "/api/v1/recetas/$id/cantidades", ['ingredientes' => $ingredients]],
            ['PATCH', "/api/v1/recetas/$id/estado", ['estado' => 'inactive']],
        ] as [$method, $path, $data]) {
            try {
                $this->json($method, $path, $data, $this->headers());
                $this->fail('La operación debía fallar al auditar.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Fallo controlado de auditoría', $exception->getMessage());
            }
        }

        // Consulta: comprueba reversión de todas las mutaciones y conservación del historial.
        $this->assertDatabaseCount('feed_recipes', 1);
        $this->assertDatabaseCount('feed_recipe_ingredients', 3);
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('feed_recipes', ['id' => $id, 'status' => 'active']);
        $this->assertDatabaseHas('feed_recipe_ingredients', ['feed_recipe_id' => $id, 'product_id' => $payload['ingredientes'][0]['producto_id'], 'quantity_grams' => 2000]);
    }

    // Flujo: mantiene las invariantes aunque un consumidor invoque los Actions fuera de HTTP.
    public function test_direct_actions_reject_unknown_fields_and_non_positive_quantities(): void
    {
        // Preparación: crea una receta mediante el caso de uso público.
        $actor = $this->signIn(['feed-recipes.manage']);
        $payload = $this->payload();
        $id = $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated()->json('data.id');
        $recipe = FeedRecipe::query()->findOrFail($id);

        // Acción: intenta una edición inválida sin pasar por FormRequest.
        try {
            app(UpdateFeedRecipeQuantitiesAction::class)->execute($recipe, [
                'ingredients' => [['product_id' => $payload['ingredientes'][0]['producto_id'], 'quantity_grams' => '0']],
            ], $actor);
            $this->fail('Debía rechazar la cantidad cero.');
        } catch (FeedRecipeConflict $exception) {
            $this->assertStringContainsString('gramos', $exception->getMessage());
        }

        // Acción: intenta inyectar atributos internos en el alta fuera de HTTP.
        $this->expectException(FeedRecipeConflict::class);
        app(CreateFeedRecipeAction::class)->execute([
            'name' => 'Inyección', 'yield_grams' => 1, 'notes' => null,
            'ingredients' => [['product_id' => $payload['ingredientes'][0]['producto_id'], 'quantity_grams' => 1]],
            'idempotency_key' => (string) Str::uuid(), 'status' => FeedRecipeStatus::Inactive,
        ], $actor);
    }

    // Flujo: una referencia de receta protege la identidad de la materia prima aunque nunca haya tenido stock.
    public function test_linked_raw_material_identity_remains_protected_without_stock_history(): void
    {
        // Preparación: vincula un producto nuevo a una receta.
        $this->signIn(['feed-recipes.manage', 'products.manage', 'products.view']);
        $payload = $this->payload();
        $this->postJson('/api/v1/recetas', $payload, $this->headers())->assertCreated();
        $productId = $payload['ingredientes'][0]['producto_id'];

        // Acción: intenta cambiar el tipo y verifica las capacidades públicas coherentes.
        $this->patchJson("/api/v1/products/$productId", ['kind' => 'supply'])->assertConflict();
        $this->getJson("/api/v1/products/$productId")->assertOk()->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name']);
        $this->assertDatabaseHas('products', ['id' => $productId, 'kind' => 'raw_material', 'base_unit' => 'g']);
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        $actor = User::factory()->create();
        foreach ($permissions as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($actor, ['*']);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $products = Product::factory()->rawMaterial()->count(3)->create();

        return [
            'nombre' => 'Ración BB', 'rendimiento_g' => 13000, 'nota' => 'Receta de prueba',
            'ingredientes' => [
                ['producto_id' => $products[0]->id, 'cantidad_g' => 2000],
                ['producto_id' => $products[1]->id, 'cantidad_g' => 5000],
                ['producto_id' => $products[2]->id, 'cantidad_g' => 6000],
            ],
        ];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
