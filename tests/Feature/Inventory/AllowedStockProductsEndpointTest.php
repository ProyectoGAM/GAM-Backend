<?php

namespace Tests\Feature\Inventory;

use App\Enums\Inventory\StockLocationStatus;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AllowedStockProductsEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: ofrece productos compatibles sin exigir un saldo previo fuera de pérdidas.
    #[TestWith(['receipt'])]
    #[TestWith(['issue'])]
    #[TestWith(['adjustment'])]
    #[TestWith(['transfer'])]
    public function test_lists_only_active_tracked_warehouse_products_without_requiring_stock(string $operation): void
    {
        // Preparación: combina productos válidos con ingredientes, fichas inactivas y huevos técnicos.
        $this->signIn();
        $location = StockLocation::factory()->create();
        $product = Product::factory()->create(['name' => 'Insumo compatible']);
        Product::factory()->rawMaterial()->create();
        Product::factory()->create(['status' => ProductStatus::Inactive]);
        Product::factory()->create(['stock_tracked' => false]);
        Product::factory()->create(['system_key' => 'generic_egg', 'kind' => 'egg', 'base_unit' => 'unit']);

        // Consulta: devuelve el catálogo compatible aunque todavía no exista un saldo.
        $this->getJson($this->endpoint($location, $operation))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => 'Insumo compatible',
                'kind' => 'supply',
                'base_unit' => 'kg',
                'available_quantity' => '0.000000',
            ])
            ->assertJsonPath('meta.total', 1);

        // Verificación: la lectura no materializa saldos, movimientos ni auditorías.
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: limita pérdidas al saldo estrictamente positivo de la planta elegida.
    public function test_losses_only_include_positive_compatible_stock_at_the_selected_location(): void
    {
        // Preparación: distribuye saldos positivos, cero y negativos entre dos plantas de distintas UP.
        $this->signIn();
        $location = $this->feedLocation();
        $otherLocation = $this->feedLocation();
        $positive = Product::factory()->rawMaterial()->create(['name' => 'Maíz disponible']);
        $zero = Product::factory()->rawMaterial()->create(['name' => 'Maíz agotado']);
        $negative = Product::factory()->rawMaterial()->create(['name' => 'Maíz con deuda']);
        $absent = Product::factory()->rawMaterial()->create(['name' => 'Maíz de otra planta']);
        $inactive = Product::factory()->rawMaterial()->create(['status' => ProductStatus::Inactive]);
        $untracked = Product::factory()->rawMaterial()->create(['stock_tracked' => false]);
        $wrongUnit = Product::factory()->rawMaterial()->create(['base_unit' => 'kg']);
        $supply = Product::factory()->create();
        foreach ([[$positive, '0.000001'], [$zero, '0'], [$negative, '-2'], [$inactive, '10'], [$untracked, '10'], [$wrongUnit, '10'], [$supply, '10']] as [$product, $quantity]) {
            StockBalance::factory()->create([
                'product_id' => $product->getKey(),
                'stock_location_id' => $location->getKey(),
                'on_hand_quantity' => $quantity,
                'allow_negative' => true,
            ]);
        }
        foreach ([$positive, $absent] as $product) {
            StockBalance::factory()->create([
                'product_id' => $product->getKey(),
                'stock_location_id' => $otherLocation->getKey(),
                'on_hand_quantity' => '100',
            ]);
        }

        // Consulta: conserva la precisión decimal y excluye stock de otra ubicación.
        $this->getJson($this->endpoint($location, 'loss'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $positive->getKey())
            ->assertJsonPath('data.0.available_quantity', '0.000001');

        // Consulta: al cambiar de planta devuelve el saldo local y el producto antes ausente.
        $this->getJson($this->endpoint($otherLocation, 'loss'))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $absent->getKey())
            ->assertJsonPath('data.0.available_quantity', '100.000000')
            ->assertJsonPath('data.1.id', $positive->getKey());

        // Consulta: ingresos sigue ofreciendo ingredientes sin saldo, en cero o negativos.
        $this->getJson($this->endpoint($location, 'receipt'))->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['id' => $negative->getKey(), 'available_quantity' => '-2.000000'])
            ->assertJsonFragment(['id' => $absent->getKey(), 'available_quantity' => '0.000000']);
    }

    // Flujo: al cambiar de depósito a planta, un insumo deja de ser elegible y se rechaza al guardar.
    public function test_location_changes_replace_compatible_products_and_reject_stale_selections_with_409(): void
    {
        // Preparación: habilita movimientos y crea productos de las dos clases de ubicación.
        $actor = $this->signIn();
        $actor->givePermissionTo(Permission::findOrCreate('inventory.move', 'web'));
        $warehouse = StockLocation::factory()->create();
        $plant = $this->feedLocation();
        $supply = Product::factory()->create();
        $ingredient = Product::factory()->rawMaterial()->create();

        // Consulta: cada ubicación ofrece exclusivamente el producto de su categoría.
        $this->getJson($this->endpoint($warehouse, 'issue'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $supply->getKey());
        $this->getJson($this->endpoint($plant, 'issue'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ingredient->getKey());

        // Mutación: una selección conservada por error en el cliente no puede atravesar la regla compartida.
        $this->postJson('/api/v1/inventory/issues', [
            'lines' => [['product_id' => $supply->getKey(), 'stock_location_id' => $plant->getKey(), 'quantity' => '1']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict()
            ->assertJsonPath('message', 'Una planta de ración sólo puede almacenar materias primas.');

        // Mutación: tampoco se permite mover un ingrediente a un depósito general.
        $this->postJson('/api/v1/inventory/issues', [
            'lines' => [['product_id' => $ingredient->getKey(), 'stock_location_id' => $warehouse->getKey(), 'quantity' => '1']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict()
            ->assertJsonPath('message', 'Las materias primas sólo pueden moverse en plantas de ración.');

        // Verificación: ambos rechazos conservan saldos, historial y auditoría.
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    // Flujo: las ubicaciones inactivas o vinculadas a galpones avícolas no ofrecen productos genéricos.
    public function test_inactive_and_unsupported_locations_return_empty_lists(): void
    {
        // Preparación: crea ubicaciones que no pueden recibir movimientos genéricos.
        $this->signIn();
        Product::factory()->create();
        Product::factory()->rawMaterial()->create();
        $inactive = StockLocation::factory()->create(['status' => StockLocationStatus::Inactive]);
        $poultryHouse = PoultryHouse::factory()->create();
        $unsupported = StockLocation::factory()->create(['poultry_house_id' => $poultryHouse->getKey()]);

        // Consulta: devuelve una colección paginada vacía para ambas ubicaciones.
        foreach ([$inactive, $unsupported] as $location) {
            $this->getJson($this->endpoint($location, 'receipt'))->assertOk()
                ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        }
    }

    // Flujo: pagina y busca únicamente dentro del catálogo compatible con la ubicación.
    public function test_search_and_pagination_preserve_compatibility(): void
    {
        // Preparación: crea coincidencias y productos que deben quedar fuera de la búsqueda.
        $this->signIn();
        $location = StockLocation::factory()->create();
        Product::factory()->create(['name' => 'Guante A']);
        $second = Product::factory()->create(['name' => 'Guante B']);
        Product::factory()->create(['name' => 'Botas']);
        Product::factory()->rawMaterial()->create(['name' => 'Guante ingrediente']);

        // Consulta: respeta el filtro sin distinguir mayúsculas y devuelve la segunda página.
        $response = $this->getJson($this->endpoint($location, 'receipt').'&search=GUANTE&per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->getKey())
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 2);

        // Consulta: los enlaces conservan ubicación, operación y búsqueda al navegar entre páginas.
        $this->getJson((string) $response->json('links.prev'))->assertOk()
            ->assertJsonPath('data.0.name', 'Guante A')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1);
    }

    // Flujo: exige una ubicación y una operación para poder resolver la compatibilidad.
    public function test_missing_location_and_operation_return_422(): void
    {
        // Preparación: autentica un lector autorizado.
        $this->signIn();

        // Consulta: comunica ambos campos obligatorios en español.
        $this->getJson('/api/v1/inventario/productos-permitidos')->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_location_id', 'operation'])
            ->assertJsonPath('errors.stock_location_id.0', 'El campo ubicación es obligatorio.')
            ->assertJsonPath('errors.operation.0', 'El campo operación es obligatorio.');
    }

    // Flujo: rechaza filtros fuera de contrato antes de consultar el catálogo.
    #[TestWith([['stock_location_id' => 999999999], 'stock_location_id'])]
    #[TestWith([['stock_location_id' => 'inválido'], 'stock_location_id'])]
    #[TestWith([['operation' => 'reversal'], 'operation'])]
    #[TestWith([['operation' => 'opening_balance'], 'operation'])]
    #[TestWith([['operation' => 'invalid'], 'operation'])]
    #[TestWith([['operation' => ['loss']], 'operation'])]
    #[TestWith([['per_page' => 101], 'per_page'])]
    #[TestWith([['per_page' => 0], 'per_page'])]
    #[TestWith([['page' => 0], 'page'])]
    #[TestWith([['search' => ['invalid']], 'search'])]
    public function test_invalid_filters_return_422(array $overrides, string $field): void
    {
        // Preparación: combina un filtro inválido con una consulta válida.
        $this->signIn();
        $location = StockLocation::factory()->create();
        $filters = array_replace(['stock_location_id' => $location->getKey(), 'operation' => 'receipt'], $overrides);

        // Consulta: devuelve el error específico del filtro inválido.
        $this->getJson('/api/v1/inventario/productos-permitidos?'.http_build_query($filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    // Flujo: impide consultar productos y saldos sin autenticación.
    public function test_unauthenticated_requests_return_401(): void
    {
        // Consulta: la sesión es obligatoria incluso sin filtros.
        $this->getJson('/api/v1/inventario/productos-permitidos')->assertUnauthorized();
    }

    // Flujo: impide consultar el catálogo de movimientos sin permiso de inventario.
    public function test_requests_without_inventory_view_return_403(): void
    {
        // Preparación: autentica un usuario sin permisos funcionales.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Consulta: la autorización se resuelve antes de validar los filtros.
        $this->getJson('/api/v1/inventario/productos-permitidos')->assertForbidden();
    }

    private function signIn(): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('inventory.view', 'web'));
        Sanctum::actingAs($actor, ['*']);

        return $actor;
    }

    private function feedLocation(): StockLocation
    {
        $house = PoultryHouse::factory()->feed()->create();

        return StockLocation::factory()->create([
            'poultry_house_id' => $house->getKey(),
            'production_unit_id' => $house->production_unit_id,
            'system_managed' => true,
        ]);
    }

    private function endpoint(StockLocation $location, string $operation): string
    {
        return '/api/v1/inventario/productos-permitidos?'.http_build_query([
            'stock_location_id' => $location->getKey(),
            'operation' => $operation,
        ]);
    }
}
