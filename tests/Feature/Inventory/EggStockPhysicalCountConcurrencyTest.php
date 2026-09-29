<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\RecordEggStockPhysicalCountAction;
use App\Actions\Inventory\RecordManualEggStockAction;
use App\Exceptions\Lots\LotsConflict;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\StockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class EggStockPhysicalCountConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateIsolatedDatabase;
    }

    private ?string $originalAppKey = null;

    private bool $hadOriginalEnvAppKey = false;

    private ?string $originalEnvAppKey = null;

    private bool $hadOriginalServerAppKey = false;

    private ?string $originalServerAppKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppKey = getenv('APP_KEY') === false ? null : getenv('APP_KEY');
        $this->hadOriginalEnvAppKey = array_key_exists('APP_KEY', $_ENV);
        $this->originalEnvAppKey = $_ENV['APP_KEY'] ?? null;
        $this->hadOriginalServerAppKey = array_key_exists('APP_KEY', $_SERVER);
        $this->originalServerAppKey = $_SERVER['APP_KEY'] ?? null;
        $applicationKey = (string) config('app.key');
        putenv('APP_KEY='.$applicationKey);
        $_ENV['APP_KEY'] = $applicationKey;
        $_SERVER['APP_KEY'] = $applicationKey;
    }

    protected function tearDown(): void
    {
        // Limpieza: elimina sólo conteos de _testing antes de revertir las migraciones de esta prueba.
        if (app()->environment('testing')
            && config('database.default') === 'pgsql'
            && str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            DB::table('egg_stock_transactions')->where('type', 'physical_count')->delete();
        }

        if ($this->originalAppKey === null) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY='.$this->originalAppKey);
        }
        if ($this->hadOriginalEnvAppKey) {
            $_ENV['APP_KEY'] = $this->originalEnvAppKey;
        } else {
            unset($_ENV['APP_KEY']);
        }
        if ($this->hadOriginalServerAppKey) {
            $_SERVER['APP_KEY'] = $this->originalServerAppKey;
        } else {
            unset($_SERVER['APP_KEY']);
        }

        parent::tearDown();
    }

    /** Solo permite migrar la base PostgreSQL aislada de pruebas. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing')
            || config('database.default') !== 'pgsql'
            || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }

        $this->migrateIsolatedDatabase();
    }

    /** Proceso: dos conteos con el mismo saldo visto compiten sobre una fila bloqueada del libro. */
    public function test_concurrent_physical_counts_allow_one_preview_and_reject_the_stale_contender(): void
    {
        // Preparación: crea dos actores independientes y fija un saldo confirmado de diez huevos.
        $actors = [];
        foreach ([1, 2] as $number) {
            $actors[$number] = User::factory()->create();
            $actors[$number]->givePermissionTo(Permission::findOrCreate('egg-stock.adjust', 'web'));
        }
        $unit = ProductionUnit::factory()->create();
        $actors[1]->givePermissionTo(Permission::findOrCreate('egg-stock.move', 'web'));
        app(RecordManualEggStockAction::class)->execute($unit, [
            'quantity' => 10,
            'reason' => 'Saldo inicial concurrente',
            'idempotency_key' => (string) Str::uuid(),
        ], $actors[1]);
        $commands = [];
        foreach ([1, 2] as $number) {
            $commands[] = [
                'actor_id' => $actors[$number]->id,
                'unit_id' => $unit->id,
                'data' => [
                    'counted_quantity' => $number === 1 ? 12 : 8,
                    'expected_balance' => 10,
                    'reason' => 'Conteo concurrente '.$number,
                    'occurred_at' => '2026-09-28',
                    'idempotency_key' => (string) Str::uuid(),
                ],
            ];
        }

        // Mutación: dos procesos ejecutan el flujo real de conteo y el bloqueo de Egg Stock.
        $tasks = [];
        foreach ($commands as $command) {
            $tasks[] = static function () use ($command): string {
                try {
                    app(RecordEggStockPhysicalCountAction::class)->execute(
                        ProductionUnit::query()->findOrFail($command['unit_id']),
                        $command['data'],
                        User::query()->findOrFail($command['actor_id']),
                    );

                    return 'recorded';
                } catch (LotsConflict) {
                    return 'stale';
                }
            };
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 30);
        sort($results);

        // Verificación: un comando compensa el saldo y el que leyó la vista vieja queda sin cambios.
        $this->assertSame(['recorded', 'stale'], $results);
        $this->assertDatabaseCount('egg_stock_transactions', 2);
        $this->assertSame(1, EggStockTransaction::query()->where('type', 'physical_count')->count());
        $this->assertSame(2, DB::table('inventory_movements')->count());
        $balance = StockBalance::query()
            ->whereIn('stock_location_id', DB::table('egg_stock_accounts')->where('production_unit_id', $unit->id)->select('stock_location_id'))
            ->value('on_hand_quantity');
        $this->assertContains((int) $balance, [8, 12]);
    }
}
