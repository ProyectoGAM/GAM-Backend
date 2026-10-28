<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateProductAction;
use App\Actions\SuppliersAndCatalogs\UpdateProductAction;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class ProductEggRestrictionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: rechaza altas y conversiones a categorías reservadas sin escrituras parciales.
    #[DataProvider('reservedKinds')]
    public function test_generic_creation_and_conversion_return_422(ProductKind|string $kind): void
    {
        // Preparación: conserva un insumo y autentica al gestor del catálogo.
        $this->signIn();
        $product = Product::factory()->create();
        $value = $kind instanceof ProductKind ? $kind->value : $kind;
        $message = 'Selecciona un tipo de producto disponible: Insumo, Materia prima u Otros.';

        // Requests: la API impide crear o convertir productos a huevos, medicamentos y vacunas.
        $this->postJson('/api/v1/products', ['sku' => 'RESERVADO', 'name' => 'Producto reservado', 'kind' => $value, 'base_unit' => 'unit'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind')->assertJsonPath('errors.kind.0', $message);
        $this->patchJson('/api/v1/products/'.$product->id, ['kind' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors('kind')->assertJsonPath('errors.kind.0', $message);
        $this->assertSame(ProductKind::Supply, $product->fresh()->kind);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: las llamadas internas tampoco pueden crear categorías reservadas.
    #[DataProvider('reservedKinds')]
    public function test_internal_creation_rejects_reserved_kinds(ProductKind|string $kind): void
    {
        // Preparación: permite al actor gestionar productos para alcanzar la guarda de negocio.
        $actor = $this->signIn();

        // Mutación: una llamada directa no elude la restricción pública.
        try {
            app(CreateProductAction::class)->execute(['sku' => 'RESERVADO', 'name' => 'Producto reservado', 'kind' => $kind, 'base_unit' => 'unit'], $actor);
            $this->fail('La acción debía impedir el alta genérica del tipo reservado.');
        } catch (SuppliersAndCatalogsConflict) {
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('activity_log', 0);
        }
    }

    // Flujo: las conversiones internas tampoco pueden eludir el límite del catálogo.
    #[DataProvider('reservedKinds')]
    public function test_internal_update_rejects_reserved_kinds(ProductKind|string $kind): void
    {
        // Preparación: conserva un insumo y un actor autorizado.
        $actor = $this->signIn();
        $product = Product::factory()->create();

        // Mutación: intenta eludir el FormRequest mediante la Action.
        try {
            app(UpdateProductAction::class)->execute($product, ['kind' => $kind], $actor);
            $this->fail('La acción debía impedir la conversión al tipo reservado.');
        } catch (SuppliersAndCatalogsConflict) {
            $this->assertSame(ProductKind::Supply, $product->fresh()->kind);
            $this->assertDatabaseCount('activity_log', 0);
        }
    }

    /** @return array<string, array{ProductKind|string}> */
    public static function reservedKinds(): array
    {
        return [
            'huevo texto' => ['egg'], 'huevo enum' => [ProductKind::Egg],
            'medicamento texto' => ['medicine'], 'medicamento enum' => [ProductKind::Medicine],
            'vacuna texto' => ['vaccine'], 'vacuna enum' => [ProductKind::Vaccine],
        ];
    }

    // Flujo: ofrece exactamente las tres categorías permitidas y coincide con OpenAPI.
    public function test_reference_options_match_creatable_contract(): void
    {
        // Preparación: autentica al gestor del catálogo.
        $this->signIn();

        // Consulta: los identificadores se mantienen estables y las etiquetas reflejan el negocio.
        $response = $this->getJson('/api/v1/reference/options')->assertOk();
        $options = array_column($response->json('data.types.products'), 'label', 'value');
        $this->assertSame(['supply' => 'Insumo', 'raw_material' => 'Materia prima', 'other' => 'Otros'], $options);

        // Consulta: el esquema de lectura conserva los tipos reservados para sus dominios e historia.
        $contract = Yaml::parseFile(base_path('contracts/openapi/products.yaml'));
        $this->assertSame(array_keys($options), $contract['components']['schemas']['CreatableKind']['enum']);
        $this->assertContains('egg', $contract['components']['schemas']['ReadableKind']['enum']);
    }

    // Flujo: conserva legibles los huevos anteriores y sus cantidades sin reclasificación automática.
    public function test_existing_egg_metadata_and_balance_are_preserved(): void
    {
        // Preparación: simula un producto histórico ajeno al huevo técnico del sistema.
        $this->signIn();
        $product = Product::factory()->create(['kind' => ProductKind::Egg]);
        $balance = StockBalance::factory()->create(['product_id' => $product->id, 'on_hand_quantity' => '123.000000']);

        // Request: permite corregir metadata manteniendo el tipo existente.
        $this->patchJson('/api/v1/products/'.$product->id, ['name' => 'Huevo histórico revisado', 'kind' => 'egg'])
            ->assertOk()->assertJsonPath('data.kind', 'egg');

        // Consultas: conserva lectura, saldo y auditoría de la única modificación solicitada.
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.name', 'Huevo histórico revisado');
        $this->getJson('/api/v1/products?kind=egg')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->assertSame('123.000000', $balance->fresh()->on_hand_quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('activity_log', ['event' => 'product_updated', 'subject_id' => $product->id]);
    }

    private function signIn(): User
    {
        $actor = User::factory()->create();
        foreach (['products.manage', 'products.view'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($actor, ['api:access']);

        return $actor;
    }
}
