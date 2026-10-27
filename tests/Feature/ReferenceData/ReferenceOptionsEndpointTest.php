<?php

namespace Tests\Feature\ReferenceData;

use App\Actions\Inventory\CreateFeedStockLocationAction;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ReferenceOptionsEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: consulta las referencias autorizadas y verifica entidades y enums vivos.
    public function test_user_with_read_permissions_receives_current_reference_options(): void
    {
        // Preparación: crea registros relacionados que deben aparecer con etiquetas legibles.
        $department = Department::factory()->create(['name' => 'Rocha']);
        $locality = Locality::factory()->create(['department_id' => $department->getKey(), 'name' => 'Chuy']);
        $unit = ProductionUnit::factory()->create(['locality_id' => $locality->getKey(), 'name' => 'Granja Norte']);
        $ruralUnit = ProductionUnit::factory()->create(['locality_id' => null, 'name' => 'Granja Rural']);
        $supplier = Supplier::factory()->create(['locality_id' => $locality->getKey(), 'name' => 'Proveedor Sur']);
        $product = Product::factory()->create(['sku' => 'ALIMENTO-001', 'name' => 'Ración inicial']);
        $location = StockLocation::factory()->create(['production_unit_id' => $unit->getKey(), 'name' => 'Depósito principal']);
        $balance = StockBalance::factory()->create(['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey()]);
        $actor = $this->userWithPermissions([
            'geography.view',
            'production-units.view',
            'suppliers.view',
            'products.view',
            'inventory.view',
        ]);
        Sanctum::actingAs($actor, ['*']);

        // Acción: solicita el catálogo de opciones de referencia.
        $response = $this->getJson('/api/v1/reference/options');

        // Verificación: devuelve valores actuales y etiquetas sin exponer inputs para adivinar IDs.
        $response->assertOk()
            ->assertJsonPath('data.localities.0.value', $locality->getKey())
            ->assertJsonPath('data.localities.0.label', 'Chuy — Rocha')
            ->assertJsonFragment(['value' => $ruralUnit->getKey(), 'label' => 'Granja Rural'])
            ->assertJsonPath('data.products.0.value', $product->getKey())
            ->assertJsonPath('data.products.0.label', 'ALIMENTO-001 — Ración inicial')
            ->assertJsonPath('data.stock_locations.0.value', $location->getKey())
            ->assertJsonPath('data.stock_locations.0.label', 'Depósito principal — Granja Norte')
            ->assertJsonPath('data.inventory_balances.0.value', $balance->getKey())
            ->assertJsonPath('data.inventory_balances.0.label', 'ALIMENTO-001 — Ración inicial · Depósito principal')
            ->assertJsonMissingPath('data.reservas')
            ->assertJsonMissingPath('data.lineas_reserva')
            ->assertJsonPath('data.types.base_units.0.value', 'unit')
            ->assertJsonPath('data.types.booleans.0.value', 'true')
            ->assertJsonCount(2, 'data.statuses.production_units')
            ->assertJsonPath('data.statuses.production_units.0.value', 'active')
            ->assertJsonPath('data.statuses.production_units.1.value', 'inactive');
    }

    // Flujo: las referencias de inventario muestran el nombre vigente de la planta y mantienen sus IDs técnicos.
    public function test_inventory_options_use_the_current_plant_name_without_farm_permissions(): void
    {
        // Preparación: crea una planta con ubicación técnica y saldo de un ingrediente.
        $actor = $this->userWithPermissions(['inventory.view']);
        Sanctum::actingAs($actor, ['*']);
        $unit = ProductionUnit::factory()->create(['name' => 'Granja Norte']);
        $house = PoultryHouse::factory()->feed()->create(['production_unit_id' => $unit->getKey(), 'name' => 'Planta original']);
        $location = app(CreateFeedStockLocationAction::class)->execute($house, $actor);
        $product = Product::factory()->create(['sku' => 'MAIZ-001', 'name' => 'Maíz', 'kind' => 'raw_material', 'base_unit' => 'g']);
        $balance = StockBalance::factory()->create(['product_id' => $product->getKey(), 'stock_location_id' => $location->getKey()]);

        // Mutación: cambia el nombre de la planta y conserva el nombre técnico almacenado.
        $house->update(['name' => 'Planta El Ombú']);

        // Consulta: las etiquetas se resuelven con el nombre actual sin exigir acceso al módulo de granjas.
        $this->getJson('/api/v1/reference/options')->assertOk()
            ->assertJsonPath('data.stock_locations.0.value', $location->getKey())
            ->assertJsonPath('data.stock_locations.0.label', 'Planta El Ombú — Granja Norte')
            ->assertJsonPath('data.inventory_balances.0.value', $balance->getKey())
            ->assertJsonPath('data.inventory_balances.0.label', 'MAIZ-001 — Maíz · Planta El Ombú')
            ->assertJsonCount(0, 'data.poultry_houses');
    }

    // Flujo: intenta consultar referencias sin sesión y confirma el límite de autenticación.
    public function test_unauthenticated_user_cannot_read_reference_options(): void
    {
        // Acción: solicita el catálogo sin un token Bearer.
        $response = $this->getJson('/api/v1/reference/options');

        // Verificación: la ruta protegida no revela ningún catálogo.
        $response->assertUnauthorized();
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
