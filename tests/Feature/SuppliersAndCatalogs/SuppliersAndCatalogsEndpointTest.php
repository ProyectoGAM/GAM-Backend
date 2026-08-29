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

    public function test_valid_supplier_payload_creates_supplier_and_audit_entry(): void
    {
        $actor = $this->userWithPermissions(['suppliers.manage']);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/v1/proveedores', [
            'name' => 'Proveedor Norte',
            'address' => 'Camino Rural 10',
        ])->assertCreated()->assertJsonPath('data.name', 'Proveedor Norte');

        $supplierId = (int) $response->json('data.id');
        $this->assertDatabaseHas('suppliers', ['id' => $supplierId, 'normalized_name' => 'proveedor norte', 'status' => 'active']);
        $this->assertDatabaseHas('activity_log', ['event' => 'supplier_created', 'subject_id' => $supplierId, 'causer_id' => $actor->getKey()]);
    }

    public function test_valid_product_payload_creates_flexible_catalog_item(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['products.manage']), ['*']);

        $this->postJson('/api/v1/productos', [
            'sku' => 'HUEVO-BOLITA',
            'name' => 'Huevo Bolita',
            'kind' => 'egg',
            'base_unit' => 'unit',
            'stock_tracked' => true,
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'egg')
            ->assertJsonPath('data.base_unit', 'unit');

        $this->assertDatabaseHas('products', ['sku' => 'HUEVO-BOLITA', 'normalized_name' => 'huevo bolita']);
    }

    public function test_authenticated_user_without_manage_permission_receives_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/proveedores', ['name' => 'Proveedor', 'address' => 'Dirección'])->assertForbidden();
    }

    public function test_duplicate_supplier_name_returns_validation_error(): void
    {
        Supplier::factory()->create(['name' => 'Proveedor Único']);
        Sanctum::actingAs($this->userWithPermissions(['suppliers.manage']), ['*']);

        $this->postJson('/api/v1/proveedores', ['name' => ' proveedor único ', 'address' => 'Otra dirección'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_duplicate_product_sku_returns_validation_error(): void
    {
        Product::factory()->create(['sku' => 'SKU-1']);
        Sanctum::actingAs($this->userWithPermissions(['products.manage']), ['*']);

        $this->postJson('/api/v1/productos', ['sku' => 'SKU-1', 'name' => 'Otro', 'kind' => 'supply', 'base_unit' => 'unit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_product_unit_can_change_before_first_movement_but_not_afterwards(): void
    {
        $actor = $this->userWithPermissions(['products.manage', 'inventory.move']);
        Sanctum::actingAs($actor, ['*']);
        $product = Product::factory()->create(['base_unit' => 'kg']);

        $this->patchJson('/api/v1/productos/'.$product->getKey(), ['base_unit' => 'unit'])
            ->assertOk()
            ->assertJsonPath('data.base_unit', 'unit');

        $this->postJson('/api/v1/inventario/ingresos', [
            'supplier_id' => Supplier::factory()->create()->getKey(),
            'lines' => [['product_id' => $product->getKey(), 'stock_location_id' => StockLocation::factory()->create()->getKey(), 'quantity' => '2']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

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
