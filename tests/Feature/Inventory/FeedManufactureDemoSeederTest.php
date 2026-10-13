<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Inventory\FeedManufactureDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FeedManufactureDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: la entrada oficial incluye una elaboración cancelada y un recuento de alimento independiente.
    public function test_official_demo_is_audited_and_replay_preserves_balances_and_history(): void
    {
        // Preparación: habilita el ambiente ficticio y aísla archivos de reportes.
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Mutación: ejecuta la carga oficial, que conserva los saldos originales de ingredientes.
        $this->seed(DatabaseSeeder::class);
        $original = FeedStockOperation::query()->where('kind', 'manufacture')->with('cancellation')->sole();
        $this->assertNotNull($original->cancellation);
        $this->assertSame(13000, $original->quantity_grams);
        $this->assertDatabaseHas('feed_stock_operations', ['kind' => 'feed_adjustment', 'balance_after' => '26000.000000']);
        $this->assertDatabaseCount('feed_stock_operations', 3);
        $this->assertSame(3, AuditEntry::query()->whereIn('event', ['feed_manufacture_recorded', 'feed_cancellation_recorded', 'feed_feed_adjustment_recorded'])->count());
        $operations = FeedStockOperation::query()->orderBy('id')->get()->toArray();
        $balances = StockBalance::query()->orderBy('id')->get()->toArray();
        $feed = FeedBalance::query()->get()->toArray();
        $movementCount = InventoryMovement::query()->count();
        $auditCount = AuditEntry::query()->count();

        // Mutación: reintenta todo el bloque sin sumar alimento ni devolver ingredientes otra vez.
        $this->seed(FeedManufactureDemoSeeder::class);
        $this->assertSame($operations, FeedStockOperation::query()->orderBy('id')->get()->toArray());
        $this->assertSame($balances, StockBalance::query()->orderBy('id')->get()->toArray());
        $this->assertSame($feed, FeedBalance::query()->get()->toArray());
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame($auditCount, AuditEntry::query()->count());
    }

    // Flujo: testing y producción sin demo habilitada no crean operaciones ficticias.
    public function test_demo_is_skipped_outside_authorized_environments(): void
    {
        // Mutación: ejecuta el seeder protegido en el entorno de testing.
        $this->seed(FeedManufactureDemoSeeder::class);

        // Consulta: confirma la ausencia de datos y movimientos.
        $this->assertDatabaseCount('feed_stock_operations', 0);
        $this->assertDatabaseCount('feed_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
