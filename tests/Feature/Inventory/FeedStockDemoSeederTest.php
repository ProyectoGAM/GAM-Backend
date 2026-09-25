<?php

namespace Tests\Feature\Inventory;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Inventory\FeedStockDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FeedStockDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: la carga local crea dos plantas feed, sus ubicaciones y stock histórico en gramos.
    public function test_local_seed_creates_feed_plants_and_gram_balances_idempotently(): void
    {
        // Preparación: habilita el entorno local y aísla los archivos de reportes demo.
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Acción: ejecuta la entrada oficial de datos demo.
        $this->seed(DatabaseSeeder::class);

        // Verificación: identifica por nombre los tres galpones avícolas originales y las dos plantas feed.
        $originalPoultryHouseNames = ['galpón norte', 'galpón sur', 'galpón ponedoras'];
        $feedPlantNames = ['planta de ración el ombú', 'planta de ración santa clara'];
        $this->assertSame(3, PoultryHouse::query()
            ->where('type', PoultryHouseType::Poultry->value)
            ->whereIn('normalized_name', $originalPoultryHouseNames)
            ->count());
        $this->assertSame(2, PoultryHouse::query()
            ->where('type', PoultryHouseType::Feed->value)
            ->whereIn('normalized_name', $feedPlantNames)
            ->count());
        $this->assertSame(2, StockLocation::query()->whereNotNull('poultry_house_id')->count());

        // Verificación: los saldos demo de cada ingrediente conservan sus equivalentes en gramos.
        $corn = Product::query()->where('sku', 'MAIZ-025')->sole();
        $soy = Product::query()->where('sku', 'SOJA-025')->sole();
        $this->assertSame('g', $corn->base_unit->value);
        $this->assertSame('g', $soy->base_unit->value);
        $this->assertSame(['1240000.000000', '760000.000000'], StockBalance::query()->where('product_id', $corn->getKey())->orderBy('stock_location_id')->pluck('on_hand_quantity')->all());
        $this->assertSame(['680000.000000', '420000.000000'], StockBalance::query()->where('product_id', $soy->getKey())->orderBy('stock_location_id')->pluck('on_hand_quantity')->all());
        $this->assertSame(12, InventoryMovementLine::query()->whereIn('product_id', [$corn->getKey(), $soy->getKey()])->count());
        $this->assertSame(3, InventoryMovement::query()->whereIn('operation_id', [
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
        ])->count());
        $this->assertSame(['g'], InventoryMovementLine::query()->whereIn('product_id', [$corn->getKey(), $soy->getKey()])->pluck('unit')->unique()->values()->all());

        // Preparación: conserva el estado de plantas, ubicaciones, saldos y movimientos antes del reintento.
        $housesBefore = PoultryHouse::query()->where('type', PoultryHouseType::Feed->value)->orderBy('id')->get()->toArray();
        $locationsBefore = StockLocation::query()->whereNotNull('poultry_house_id')->orderBy('id')->get()->toArray();
        $balancesBefore = StockBalance::query()->whereIn('product_id', [$corn->getKey(), $soy->getKey()])->orderBy('id')->get()->toArray();
        $movementsBefore = InventoryMovement::query()->whereIn('operation_id', [
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
        ])->orderBy('id')->get()->toArray();

        // Acción: repite exclusivamente la carga de plantas de ración.
        $this->seed(FeedStockDemoSeeder::class);

        // Verificación: no duplica plantas, ubicaciones, movimientos ni reescribe saldos existentes.
        $this->assertSame($housesBefore, PoultryHouse::query()->where('type', PoultryHouseType::Feed->value)->orderBy('id')->get()->toArray());
        $this->assertSame($locationsBefore, StockLocation::query()->whereNotNull('poultry_house_id')->orderBy('id')->get()->toArray());
        $this->assertSame($balancesBefore, StockBalance::query()->whereIn('product_id', [$corn->getKey(), $soy->getKey()])->orderBy('id')->get()->toArray());
        $this->assertSame($movementsBefore, InventoryMovement::query()->whereIn('operation_id', [
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
        ])->orderBy('id')->get()->toArray());
    }

    // Flujo: omite la carga ficticia de alimentación fuera del entorno local.
    public function test_feed_stock_demo_is_skipped_outside_local_environment(): void
    {
        // Preparación: conserva el entorno de pruebas normal.
        $this->app->instance('env', 'testing');

        // Acción: invoca directamente el seeder protegido por ambiente.
        $this->seed(FeedStockDemoSeeder::class);

        // Verificación: no persiste plantas ni inventario ficticio.
        $this->assertDatabaseCount('poultry_houses', 0);
        $this->assertDatabaseCount('stock_locations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
