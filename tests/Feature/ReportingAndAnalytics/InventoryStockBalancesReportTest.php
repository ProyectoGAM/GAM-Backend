<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Actions\Inventory\CreateFeedStockLocationAction;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class InventoryStockBalancesReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: excluye del agregado el saldo de una planta de ración inactiva y conserva el detalle.
    public function test_grouped_balances_exclude_only_inactive_feed_plants(): void
    {
        // Preparación: crea materias primas y un suministro en plantas activas e inactivas.
        $actor = $this->userWithPermissions(['reports.view', 'inventory.view']);
        Sanctum::actingAs($actor, ['*']);
        $rawMaterial = Product::factory()->create([
            'name' => 'Maíz reporte',
            'kind' => ProductKind::RawMaterial,
            'base_unit' => BaseUnit::Gram,
            'stock_tracked' => true,
        ]);
        $supply = Product::factory()->create([
            'name' => 'Suministro reporte',
            'kind' => ProductKind::Supply,
            'base_unit' => BaseUnit::Kilogram,
            'stock_tracked' => true,
        ]);
        $activeLocation = app(CreateFeedStockLocationAction::class)->execute(PoultryHouse::factory()->feed()->create(), $actor);
        $inactiveLocation = app(CreateFeedStockLocationAction::class)->execute(PoultryHouse::factory()->feed()->inactive()->create(), $actor);
        $genericLocation = StockLocation::factory()->create();
        StockBalance::factory()->for($rawMaterial, 'product')->for($activeLocation, 'stockLocation')->create(['on_hand_quantity' => '7.000000']);
        StockBalance::factory()->for($rawMaterial, 'product')->for($inactiveLocation, 'stockLocation')->create(['on_hand_quantity' => '11.000000']);
        StockBalance::factory()->for($supply, 'product')->for($genericLocation, 'stockLocation')->create(['on_hand_quantity' => '5.000000']);

        // Acción: agrupa los saldos por producto y unidad base.
        $grouped = $this->postJson('/api/v1/reports/inventory.stock-balances/previews', [
            'groupings' => ['product'],
            'metrics' => ['available_stock'],
        ])->assertOk();

        // Verificación: la planta inactiva no aporta maíz, pero sí conserva los demás productos.
        $rows = collect($grouped->json('data.rows'));
        $this->assertSame('7.000000', $rows->firstWhere('product', 'Maíz reporte')['available_stock']);
        $this->assertSame('5.000000', $rows->firstWhere('product', 'Suministro reporte')['available_stock']);

        // Acción: consulta el detalle sin agrupación.
        $detail = $this->postJson('/api/v1/reports/inventory.stock-balances/previews', [
            'columns' => ['product', 'stock_location', 'available_quantity'],
        ])->assertOk();

        // Verificación: el saldo real de la planta inactiva sigue visible en el detalle.
        $detailRows = collect($detail->json('data.rows'));
        $inactiveMaize = $detailRows
            ->where('product', 'Maíz reporte')
            ->firstWhere('stock_location', $inactiveLocation->name);
        $this->assertSame('11.000000', $inactiveMaize['available_quantity']);
        $this->assertSame(3, $detailRows->count());
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
