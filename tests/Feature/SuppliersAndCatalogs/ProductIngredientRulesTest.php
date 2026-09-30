<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\UpdateProductAction;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ProductIngredientRulesTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea una materia prima en gramos y completa el control de stock por defecto.
    public function test_raw_material_creation_requires_grams_and_enables_stock_tracking(): void
    {
        // Preparación: autentica un gestor de productos.
        Sanctum::actingAs($this->userWithPermissions(['products.manage']), ['*']);

        // Acción: crea una materia prima omitiendo el indicador opcional de stock.
        $this->postJson('/api/v1/products', [
            'sku' => 'MAIZ-REGLA-001',
            'name' => 'Maíz regla',
            'kind' => ProductKind::RawMaterial->value,
            'base_unit' => BaseUnit::Gram->value,
        ])->assertCreated()
            ->assertJsonPath('data.base_unit', BaseUnit::Gram->value)
            ->assertJsonPath('data.stock_tracked', true);

        // Verificación: confirma la persistencia del inventario en la unidad estable.
        $this->assertDatabaseHas('products', [
            'sku' => 'MAIZ-REGLA-001',
            'kind' => ProductKind::RawMaterial->value,
            'base_unit' => BaseUnit::Gram->value,
            'stock_tracked' => true,
        ]);

        // Acción: intenta registrar materias primas con unidad o control incompatibles.
        $this->postJson('/api/v1/products', [
            'sku' => 'MAIZ-REGLA-002',
            'name' => 'Maíz kilogramos',
            'kind' => ProductKind::RawMaterial->value,
            'base_unit' => BaseUnit::Kilogram->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['base_unit']);
        $this->postJson('/api/v1/products', [
            'sku' => 'MAIZ-REGLA-003',
            'name' => 'Maíz sin stock',
            'kind' => ProductKind::RawMaterial->value,
            'base_unit' => BaseUnit::Gram->value,
            'stock_tracked' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['stock_tracked']);
    }

    // Flujo: rechaza cambios de tipo, unidad y control cuando existe historial de una materia prima.
    public function test_raw_material_configuration_is_immutable_after_inventory_history(): void
    {
        // Preparación: persiste una materia prima con un saldo histórico y autentica al gestor.
        $actor = $this->userWithPermissions(['products.manage']);
        $rawMaterial = Product::factory()->create([
            'kind' => ProductKind::RawMaterial,
            'base_unit' => BaseUnit::Gram,
            'stock_tracked' => true,
        ]);
        StockBalance::factory()->for($rawMaterial, 'product')->create(['on_hand_quantity' => '4.000000']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: intenta cambiar la unidad mediante el contrato HTTP.
        $this->patchJson('/api/v1/products/'.$rawMaterial->getKey(), ['base_unit' => BaseUnit::Kilogram->value])
            ->assertUnprocessable()->assertJsonValidationErrors(['base_unit']);

        // Acción: intenta cambiar el tipo mediante la acción para cubrir el guard contra bypasses.
        $this->expectException(SuppliersAndCatalogsConflict::class);
        app(UpdateProductAction::class)->execute($rawMaterial, ['kind' => ProductKind::Supply->value], $actor);
    }

    // Flujo: impide convertir a materia prima un producto que ya tiene saldos.
    public function test_product_with_history_cannot_become_raw_material(): void
    {
        // Preparación: crea un suministro con saldo persistido y un usuario autorizado.
        $actor = $this->userWithPermissions(['products.manage']);
        $supply = Product::factory()->create([
            'kind' => ProductKind::Supply,
            'base_unit' => BaseUnit::Kilogram,
            'stock_tracked' => true,
        ]);
        StockBalance::factory()->for($supply, 'product')->create(['on_hand_quantity' => '2.000000']);

        // Acción: intenta reclasificar el producto con una configuración formalmente válida de ingrediente.
        $this->expectException(SuppliersAndCatalogsConflict::class);
        app(UpdateProductAction::class)->execute($supply, [
            'kind' => ProductKind::RawMaterial->value,
            'base_unit' => BaseUnit::Gram->value,
            'stock_tracked' => true,
        ], $actor);
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
