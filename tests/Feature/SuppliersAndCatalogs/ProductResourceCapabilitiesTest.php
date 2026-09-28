<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProductResourceCapabilitiesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_and_products_manager_receive_capabilities_for_normal_products(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/products/'.$product->getKey())
            ->assertOk()
            ->assertJsonPath('data.system_managed', false)
            ->assertJsonPath('data.specialized_owner', null)
            ->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name', 'kind', 'base_unit', 'stock_tracked'])
            ->assertJsonPath('data.capabilities.activate', false)
            ->assertJsonPath('data.capabilities.deactivate', true)
            ->assertJsonMissingPath('data.system_key');

        $manager = $this->userWithPermissions(['products.manage', 'products.view']);
        Sanctum::actingAs($manager, ['*']);
        Product::factory()->create(['status' => ProductStatus::Inactive]);

        $this->getJson('/api/v1/products?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.capabilities.editable_fields', ['sku', 'name', 'kind', 'base_unit', 'stock_tracked'])
            ->assertJsonPath('data.0.capabilities.activate', true)
            ->assertJsonPath('data.0.capabilities.deactivate', false);
    }

    public function test_read_only_user_receives_no_mutation_capabilities(): void
    {
        $product = Product::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['products.view']), ['*']);

        $this->getJson('/api/v1/products/'.$product->getKey())
            ->assertOk()
            ->assertJsonPath('data.capabilities.editable_fields', [])
            ->assertJsonPath('data.capabilities.activate', false)
            ->assertJsonPath('data.capabilities.deactivate', false);
    }

    public function test_specialized_ownership_uses_actual_product_data(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $egg = Product::factory()->create(['system_key' => 'generic_egg']);
        $this->getJson('/api/v1/products/'.$egg->getKey())
            ->assertOk()
            ->assertJsonPath('data.system_managed', true)
            ->assertJsonPath('data.specialized_owner.type', 'egg_stock')
            ->assertJsonPath('data.capabilities.editable_fields', [])
            ->assertJsonPath('data.capabilities.activate', false)
            ->assertJsonPath('data.capabilities.deactivate', false)
            ->assertJsonMissingPath('data.system_key');

        $vaccineProduct = Product::factory()->vaccine()->create();
        $vaccine = Vaccine::factory()->create(['product_id' => $vaccineProduct->getKey()]);
        $this->getJson('/api/v1/products/'.$vaccineProduct->getKey())
            ->assertOk()
            ->assertJsonPath('data.system_managed', false)
            ->assertJsonPath('data.specialized_owner.type', 'vaccine')
            ->assertJsonPath('data.specialized_owner.id', $vaccine->public_id)
            ->assertJsonPath('data.capabilities.editable_fields', [])
            ->assertJsonPath('data.capabilities.activate', false)
            ->assertJsonPath('data.capabilities.deactivate', false);

        $orphanVaccine = Product::factory()->vaccine()->create();
        $this->assertNormalOwnerlessProduct($orphanVaccine);

        $medicine = Product::factory()->create(['kind' => ProductKind::Medicine]);
        $this->assertNormalOwnerlessProduct($medicine);
    }

    public function test_history_restricts_only_the_fields_known_to_be_immutable(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['products.manage', 'products.view']), ['*']);

        $rawMaterialWithoutHistory = Product::factory()->rawMaterial()->create();
        $this->getJson('/api/v1/products/'.$rawMaterialWithoutHistory->getKey())
            ->assertOk()
            ->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name', 'kind', 'base_unit', 'stock_tracked']);

        $rawMaterialWithBalance = Product::factory()->rawMaterial()->create();
        StockBalance::factory()->for($rawMaterialWithBalance, 'product')->create();
        $this->getJson('/api/v1/products/'.$rawMaterialWithBalance->getKey())
            ->assertOk()
            ->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name']);

        $normalWithMovement = Product::factory()->create();
        InventoryMovementLine::factory()->create(['product_id' => $normalWithMovement->getKey()]);
        $this->getJson('/api/v1/products/'.$normalWithMovement->getKey())
            ->assertOk()
            ->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name', 'kind', 'stock_tracked']);
    }

    public function test_product_list_batches_metadata_queries_as_row_count_grows(): void
    {
        Product::factory()->count(8)->create();
        Sanctum::actingAs($this->admin(), ['*']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonCount(8, 'data');
        $queries = array_map(static fn (array $query): string => strtolower((string) $query['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $standaloneQueriesFor = static function (array $queries, string $table): array {
            return array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')
                && ! str_contains($sql, 'from "products"'));
        };

        $this->assertCount(1, $standaloneQueriesFor($queries, 'vaccines'));
        $this->assertCount(0, $standaloneQueriesFor($queries, 'inventory_movement_lines'));
        $this->assertCount(0, $standaloneQueriesFor($queries, 'stock_balances'));
    }

    private function assertNormalOwnerlessProduct(Product $product): void
    {
        $this->getJson('/api/v1/products/'.$product->getKey())
            ->assertOk()
            ->assertJsonPath('data.system_managed', false)
            ->assertJsonPath('data.specialized_owner', null)
            ->assertJsonPath('data.capabilities.editable_fields', ['sku', 'name', 'kind', 'base_unit', 'stock_tracked']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
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
