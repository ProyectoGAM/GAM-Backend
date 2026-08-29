<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SuppliersAndCatalogsEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea un proveedor y verifica persistencia, normalización y auditoría.
    public function test_valid_supplier_payload_creates_supplier_and_audit_entry(): void
    {
        // Preparación: autentica al usuario con permiso de gestión.
        $actor = $this->userWithPermissions(['suppliers.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: registra el proveedor mediante la API.
        $response = $this->postJson('/api/v1/proveedores', [
            'name' => 'Proveedor Norte',
            'address' => 'Camino Rural 10',
        ])->assertCreated()->assertJsonPath('data.name', 'Proveedor Norte');

        $supplierId = (int) $response->json('data.id');

        // Verificación: confirma datos normalizados y auditoría del alta.
        $this->assertDatabaseHas('suppliers', ['id' => $supplierId, 'normalized_name' => 'proveedor norte', 'status' => 'active']);
        $this->assertDatabaseHas('activity_log', ['event' => 'supplier_created', 'subject_id' => $supplierId, 'causer_id' => $actor->getKey()]);
    }

    // Flujo: crea un producto de catálogo y verifica su tipo, unidad y persistencia.
    public function test_valid_product_payload_creates_flexible_catalog_item(): void
    {
        // Preparación: autentica al usuario con permiso de gestión de productos.
        Sanctum::actingAs($this->userWithPermissions(['products.manage']), ['*']);

        // Acción: registra el producto del catálogo.
        $this->postJson('/api/v1/productos', [
            'sku' => 'HUEVO-BOLITA',
            'name' => 'Huevo Bolita',
            'kind' => 'egg',
            'base_unit' => 'unit',
            'stock_tracked' => true,
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'egg')
            ->assertJsonPath('data.base_unit', 'unit');

        // Verificación: confirma SKU y nombre normalizado persistidos.
        $this->assertDatabaseHas('products', ['sku' => 'HUEVO-BOLITA', 'normalized_name' => 'huevo bolita']);
    }

    // Flujo: autentica un usuario sin permiso y verifica que el alta es prohibida.
    public function test_authenticated_user_without_manage_permission_receives_forbidden(): void
    {
        // Preparación: autentica un usuario sin permisos de gestión.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Acción: intenta crear un proveedor.
        $this->postJson('/api/v1/proveedores', ['name' => 'Proveedor', 'address' => 'Dirección'])->assertForbidden();
    }

    // Flujo: crea un proveedor duplicado y verifica el error de validación.
    public function test_duplicate_supplier_name_returns_validation_error(): void
    {
        // Preparación: persiste el proveedor original y autentica al gestor.
        Supplier::factory()->create(['name' => 'Proveedor Único']);
        Sanctum::actingAs($this->userWithPermissions(['suppliers.manage']), ['*']);

        // Acción: intenta repetir el nombre con distinta capitalización.
        $this->postJson('/api/v1/proveedores', ['name' => ' proveedor único ', 'address' => 'Otra dirección'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    // Flujo: crea un producto con SKU duplicado y verifica el error de validación.
    public function test_duplicate_product_sku_returns_validation_error(): void
    {
        // Preparación: persiste el SKU original y autentica al gestor.
        Product::factory()->create(['sku' => 'SKU-1']);
        Sanctum::actingAs($this->userWithPermissions(['products.manage']), ['*']);

        // Acción: intenta crear otro producto con el mismo SKU.
        $this->postJson('/api/v1/productos', ['sku' => 'SKU-1', 'name' => 'Otro', 'kind' => 'supply', 'base_unit' => 'unit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    // Flujo: cambia la unidad antes del primer movimiento y verifica que luego queda bloqueada.
    public function test_product_unit_can_change_before_first_movement_but_not_afterwards(): void
    {
        // Preparación: crea un producto en kilogramos y autentica los permisos requeridos.
        $actor = $this->userWithPermissions(['products.manage', 'inventory.move']);
        Sanctum::actingAs($actor, ['*']);
        $product = Product::factory()->create(['base_unit' => 'kg']);

        // Acción 1: cambia la unidad antes de registrar movimientos.
        $this->patchJson('/api/v1/productos/'.$product->getKey(), ['base_unit' => 'unit'])
            ->assertOk()
            ->assertJsonPath('data.base_unit', 'unit');

        // Acción 2: registra el primer movimiento del producto.
        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => Supplier::factory()->create()->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => StockLocation::factory()->create()->getKey(), 'quantity' => '2']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        // Acción 3: intenta cambiar la unidad después del primer movimiento.
        $this->patchJson('/api/v1/productos/'.$product->getKey(), ['base_unit' => 'kg'])
            ->assertConflict();
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
