<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Actions\ManagementPlans\RecordFeedSupplyAction;
use App\Actions\SuppliersAndCatalogs\CreateProductAction;
use App\Actions\SuppliersAndCatalogs\UpdateProductAction;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\ManagementPlans\FeedSupplyRetired;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\ManagementPlans\RationChange;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Database\Seeders\ReportingAndAnalytics\ReportingDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class FinishedFeedRetirementTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: rechaza nuevas altas y conversiones a ración con errores de validación asociados a kind.
    public function test_product_writes_return_422_for_finished_feed(): void
    {
        // Preparación: autentica un gestor y conserva un suministro genérico existente.
        $this->signIn();
        $product = Product::factory()->create();
        $message = 'Selecciona un tipo de producto disponible. La ración se gestiona mediante recetas y saldos por unidad productiva.';

        // Requests: ambos contratos rechazan el tipo retirado antes de guardar productos o auditorías.
        $this->postJson('/api/v1/products', ['sku' => 'RACION-NUEVA', 'name' => 'Ración nueva', 'kind' => 'finished_feed', 'base_unit' => 'kg'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind')->assertJsonPath('errors.kind.0', $message);
        $this->patchJson('/api/v1/products/'.$product->id, ['kind' => 'finished_feed'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind')->assertJsonPath('errors.kind.0', $message);
        $this->assertSame(ProductKind::Supply, $product->fresh()->kind);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: las acciones de alta tampoco permiten crear ración mediante llamadas internas o importaciones.
    #[DataProvider('retiredKinds')]
    public function test_internal_creation_rejects_the_retired_kind(ProductKind|string $kind): void
    {
        // Preparación: permite el alta de productos al actor para alcanzar la guarda de dominio.
        $actor = $this->signIn();

        // Mutación: una llamada interna no puede eludir la validación HTTP.
        try {
            app(CreateProductAction::class)->execute(['sku' => 'INTERNA', 'name' => 'Ración interna', 'kind' => $kind, 'base_unit' => 'kg'], $actor);
            $this->fail('La acción debía rechazar finished_feed.');
        } catch (SuppliersAndCatalogsConflict) {
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('activity_log', 0);
        }
    }

    // Flujo: la acción de actualización tampoco puede convertir productos por fuera del controlador.
    #[DataProvider('retiredKinds')]
    public function test_internal_update_rejects_conversion_to_the_retired_kind(ProductKind|string $kind): void
    {
        // Preparación: conserva un producto operativo y permiso de edición.
        $actor = $this->signIn();
        $product = Product::factory()->create();

        // Mutación: rechaza tanto el valor del enum como su representación textual.
        try {
            app(UpdateProductAction::class)->execute($product, ['kind' => $kind], $actor);
            $this->fail('La acción debía impedir la conversión a finished_feed.');
        } catch (SuppliersAndCatalogsConflict) {
            $this->assertSame(ProductKind::Supply, $product->fresh()->kind);
            $this->assertDatabaseCount('activity_log', 0);
        }
    }

    /** @return array<string, array{ProductKind|string}> */
    public static function retiredKinds(): array
    {
        return ['texto' => ['finished_feed'], 'enum' => [ProductKind::FinishedFeed]];
    }

    // Flujo: productos antiguos, saldos, movimientos e instantáneas de Manejo siguen consultables.
    public function test_historical_products_and_references_remain_readable_without_reclassification(): void
    {
        // Preparación: simula filas anteriores a la retirada con sus cantidades y nombres guardados.
        $this->signIn();
        $legacy = Product::factory()->create(['kind' => ProductKind::FinishedFeed, 'name' => 'Ración histórica']);
        $stock = StockBalance::factory()->create(['product_id' => $legacy->id, 'on_hand_quantity' => '123.500000']);
        $line = InventoryMovementLine::factory()->create(['product_id' => $legacy->id, 'stock_location_id' => $stock->stock_location_id]);
        $flock = Flock::factory()->create();
        $snapshot = ['id' => $legacy->id, 'name' => 'Nombre histórico guardado', 'kind' => 'finished_feed'];
        $change = RationChange::factory()->for($flock)->create(['finished_feed_product_id' => $legacy->id, 'finished_feed_product_snapshot' => $snapshot]);
        $snapshot = $change->fresh()->finished_feed_product_snapshot;
        FeedSupply::factory()->for($flock)->create(['product_id' => $legacy->id, 'product_name_snapshot' => 'Nombre histórico guardado']);

        // Request: permite editar metadata conservando explícitamente el tipo histórico existente.
        $this->patchJson('/api/v1/products/'.$legacy->id, ['name' => 'Nombre actualizado', 'kind' => 'finished_feed'])->assertOk();

        // Consultas: el cast del enum y todos los recursos históricos siguen resolviendo el producto.
        $this->getJson('/api/v1/products/'.$legacy->id)->assertOk()->assertJsonPath('data.kind', 'finished_feed');
        $this->getJson('/api/v1/products?kind=finished_feed')->assertOk()->assertJsonPath('data.0.id', $legacy->id);
        $this->getJson('/api/v1/inventory/balances')->assertOk()
            ->assertJsonPath('data.0.product.kind', 'finished_feed')->assertJsonPath('data.0.available_quantity', '123.500000');
        $this->getJson('/api/v1/inventory/movements/'.$line->inventory_movement_id)->assertOk()
            ->assertJsonPath('data.lines.0.product.kind', 'finished_feed');
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/manejos?type=ration_change')->assertOk()
            ->assertJsonPath('data.0.module_data.finished_feed_product.name', 'Nombre histórico guardado');
        $this->getJson('/api/v1/flocks/'.$flock->public_id.'/alimentacion')->assertOk()
            ->assertJsonPath('data.0.product', 'Nombre histórico guardado');
        $this->assertSame($snapshot, $change->fresh()->finished_feed_product_snapshot);
        $this->assertSame('123.500000', $stock->fresh()->on_hand_quantity);
    }

    // Flujo: retira las opciones de alta y el selector antiguo sin ocultar productos históricos del catálogo.
    public function test_available_options_exclude_finished_feed_and_legacy_supply_selector_is_empty(): void
    {
        // Preparación: conserva un alimento antiguo activo para comprobar que no se ofrece como carga nueva.
        $this->signIn();
        Product::factory()->create(['kind' => ProductKind::FinishedFeed]);

        // Consultas: los seis tipos operativos permanecen disponibles y el selector retirado queda vacío.
        $response = $this->getJson('/api/v1/reference/options')->assertOk()->assertJsonCount(6, 'data.types.products');
        $this->assertNotContains('finished_feed', array_column($response->json('data.types.products'), 'value'));
        $this->getJson('/api/v1/alimentacion/productos')->assertOk()->assertJsonCount(0, 'data');
    }

    // Flujo: un intento interno de suministro no toca recetas, composición, saldo ni mínimo.
    public function test_retired_internal_supply_preserves_recipe_ingredients_balances_and_minimums(): void
    {
        // Preparación: conserva una receta y su cuenta vigente junto a un producto histórico independiente.
        $actor = $this->signIn();
        $flock = Flock::factory()->create();
        $recipe = FeedRecipe::factory()->create();
        $ingredient = FeedRecipeIngredient::factory()->create(['feed_recipe_id' => $recipe->id]);
        $balance = FeedBalance::factory()->create(['feed_recipe_id' => $recipe->id, 'production_unit_id' => $flock->production_unit_id,
            'quantity_grams' => 800000, 'minimum_quantity_grams' => 300000]);
        $legacy = Product::factory()->create(['kind' => 'finished_feed']);
        $before = [$recipe->fresh()->getAttributes(), $ingredient->fresh()->getAttributes(), $balance->fresh()->getAttributes()];

        // Mutación: el flujo antiguo no puede generar suministros ni consumos nuevos.
        try {
            app(RecordFeedSupplyAction::class)->execute($flock, ['idempotency_key' => (string) Str::uuid(), 'product_id' => $legacy->id,
                'quantity' => '25', 'unit' => 'kg', 'occurred_at' => now()->toIso8601String()], $actor);
            $this->fail('El suministro nuevo debía estar retirado.');
        } catch (FeedSupplyRetired) {
            $this->assertSame($before, [$recipe->fresh()->getAttributes(), $ingredient->fresh()->getAttributes(), $balance->fresh()->getAttributes()]);
            $this->assertDatabaseCount('feed_supplies', 0);
            $this->assertDatabaseCount('feed_consumptions', 0);
            $this->assertDatabaseCount('feed_stock_operations', 0);
            $this->assertDatabaseCount('flock_operations', 0);
        }
    }

    // Flujo: la demo de reportes deja de crear raciones y suministros sin reescribir registros anteriores.
    public function test_reporting_demo_does_not_create_or_rewrite_legacy_feed_products_and_supplies(): void
    {
        // Preparación: habilita la demo local sobre una base de testing con un administrador funcional.
        $actor = $this->signIn();
        config(['auth.admin.email' => $actor->email]);
        $this->app->instance('env', 'local');
        $flock = Flock::factory()->create();

        // Mutación: la demo puede repetirse sin introducir productos ni suministros retirados.
        $this->seed(ReportingDemoSeeder::class);
        $this->seed(ReportingDemoSeeder::class);
        $this->assertSame(0, Product::query()->where('kind', 'finished_feed')->count());
        $this->assertDatabaseCount('feed_supplies', 0);

        // Preparación: simula una base que ya traía producto y suministro históricos.
        $legacy = Product::factory()->create(['kind' => 'finished_feed']);
        $supply = FeedSupply::factory()->for($flock)->create(['product_id' => $legacy->id]);
        $before = [$legacy->fresh()->getAttributes(), $supply->fresh()->getAttributes()];

        // Mutación: volver a sembrar tampoco modifica las filas antiguas.
        $this->seed(ReportingDemoSeeder::class);
        $this->assertSame($before, [$legacy->fresh()->getAttributes(), $supply->fresh()->getAttributes()]);
        $this->assertSame(1, Product::query()->where('kind', 'finished_feed')->count());
        $this->assertDatabaseCount('feed_supplies', 1);
    }

    // Flujo: retirar el suministro no elimina los límites de autenticación y permiso funcional.
    public function test_retired_supply_keeps_authentication_and_management_permission_checks(): void
    {
        // Preparación: dispone un lote consultable sin autenticar todavía al solicitante.
        $flock = Flock::factory()->create();
        $url = '/api/v1/flocks/'.$flock->public_id.'/alimentacion';

        // Requests: las credenciales y permisos se comprueban antes de comunicar la retirada.
        $this->postJson($url, [])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($url, [])->assertForbidden();
        $this->assertDatabaseCount('feed_supplies', 0);
    }

    // Flujo: el contrato de productos publica rutas reales y referencias de esquemas resolubles.
    public function test_product_contract_routes_and_local_references_are_valid(): void
    {
        // Preparación: carga la especificación pública nueva del catálogo.
        $contract = Yaml::parseFile(base_path('contracts/openapi/products.yaml'));

        // Consulta: cada operación documentada resuelve una ruta del backend.
        foreach ($contract['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (! in_array($method, ['get', 'post', 'patch'], true)) {
                    continue;
                }
                $uri = preg_replace('/\{[^}]+\}/', '1', $path);
                $route = Route::getRoutes()->match(Request::create('/api/v1'.$uri, strtoupper($method)));
                $this->assertNotNull($route->getActionName());
            }
        }

        // Consulta: cada referencia local apunta a un esquema o respuesta disponible.
        $nodes = [$contract];
        while ($nodes !== []) {
            foreach (array_pop($nodes) as $key => $value) {
                if ($key === '$ref') {
                    $target = $contract;
                    foreach (explode('/', substr($value, 2)) as $segment) {
                        $this->assertArrayHasKey($segment, $target);
                        $target = $target[$segment];
                    }
                } elseif (is_array($value)) {
                    $nodes[] = $value;
                }
            }
        }
    }

    private function signIn(): User
    {
        $actor = User::factory()->create();
        foreach (['products.manage', 'products.view', 'inventory.view', 'management-plans.view', 'management-plans.execute',
            'egg-stock.move', 'reports.view', 'egg-collections.view'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($actor);

        return $actor;
    }
}
