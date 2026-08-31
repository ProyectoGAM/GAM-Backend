<?php

namespace Tests\Feature\ReferenceData;

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
        $response = $this->getJson('/api/v1/referencias/opciones');

        // Verificación: devuelve valores actuales y etiquetas sin exponer inputs para adivinar IDs.
        $response->assertOk()
            ->assertJsonPath('data.localidades.0.value', $locality->getKey())
            ->assertJsonPath('data.localidades.0.label', 'Chuy — Rocha')
            ->assertJsonPath('data.productos.0.value', $product->getKey())
            ->assertJsonPath('data.productos.0.label', 'ALIMENTO-001 — Ración inicial')
            ->assertJsonPath('data.ubicaciones_stock.0.value', $location->getKey())
            ->assertJsonPath('data.saldos_inventario.0.value', $balance->getKey())
            ->assertJsonMissingPath('data.reservas')
            ->assertJsonMissingPath('data.lineas_reserva')
            ->assertJsonPath('data.tipos.unidades_base.0.value', 'unit')
            ->assertJsonPath('data.tipos.booleanos.0.value', 'true');
    }

    // Flujo: intenta consultar referencias sin sesión y confirma el límite de autenticación.
    public function test_unauthenticated_user_cannot_read_reference_options(): void
    {
        // Acción: solicita el catálogo sin un token Bearer.
        $response = $this->getJson('/api/v1/referencias/opciones');

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
