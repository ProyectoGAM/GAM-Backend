<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\ValueObjects\Lots\FlockAge;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Lots\EggHistoryDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EggHistoryDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea ocho semanas de producción, bajas y pesajes con stock y auditoría, sin duplicar al repetir.
    public function test_local_seeder_creates_repeatable_egg_history_with_stock_and_weighings(): void
    {
        // Preparación: habilita la demo general y fija la fecha de los historiales.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'America/Montevideo'));
        $this->app->instance('env', 'local');
        Storage::fake('local');
        $previousAuditCount = DB::table('activity_log')->count();

        // Mutación: la entrada general registra también el historial mediante las Actions de negocio.
        $this->seed(DatabaseSeeder::class);

        // Consulta: comprueba edad, población, fechas, producción y variedad de muestras.
        $flock = Flock::query()->where('code', 'LOT-EGG-TEST')->firstOrFail();
        $this->assertSame(30, FlockAge::on($flock->entry_date->toDateString(), CarbonImmutable::now(), config('lots.timezone'))->week);
        $this->assertSame(240, $flock->initial_quantity);
        $this->assertSame(229, $flock->current_quantity);
        $this->assertSame(240, PoultryHouse::query()->findOrFail($flock->poultry_house_id)->bird_capacity);
        $this->assertSame(8, MortalityRecord::query()->where('flock_id', $flock->id)->count());
        $this->assertSame(11, (int) MortalityRecord::query()->where('flock_id', $flock->id)->sum('quantity'));
        $collections = EggCollection::query()->where('flock_id', $flock->id)->orderBy('occurred_at')->get();
        $this->assertCount(168, $collections);
        $this->assertSame(9291, $collections->sum('quantity'));
        $this->assertSame('2026-08-18', $collections->first()->occurred_at->setTimezone(config('lots.timezone'))->toDateString());
        $this->assertSame('2026-10-12', $collections->last()->occurred_at->setTimezone(config('lots.timezone'))->toDateString());
        $this->assertSame(8, DailyWeighing::query()->where('flock_id', $flock->id)->count());
        $entries = DailyWeighingEntry::query()->whereHas('dailyWeighing', fn ($query) => $query->where('flock_id', $flock->id))->get();
        $this->assertCount(96, $entries);
        $this->assertSame(80, $entries->where('mode', 'individual')->count());
        $this->assertSame(16, $entries->where('mode', 'group')->count());

        // Consulta: cada recolección tiene su ingreso físico y su auditoría en la misma operación.
        $transactions = EggStockTransaction::query()->where('reference_type', 'egg_collection')->whereIn('reference_id', $collections->pluck('public_id'))->get();
        $this->assertCount(168, $transactions);
        $this->assertSame(9291, $transactions->sum('quantity'));
        $movementOperationIds = InventoryMovement::query()->where('reference_type', 'egg_stock_transaction')->whereIn('reference_id', $transactions->pluck('public_id'))->pluck('operation_id');
        $this->assertSame($collections->pluck('operation_id')->sort()->values()->all(), $movementOperationIds->sort()->values()->all());
        $account = EggStockAccount::query()->where('production_unit_id', $flock->production_unit_id)->firstOrFail();
        $balance = StockBalance::query()->where('product_id', $account->product_id)->where('stock_location_id', $account->stock_location_id)->firstOrFail();
        $this->assertSame(9279, (int) $balance->on_hand_quantity);
        $this->assertSame(168, DB::table('activity_log')->where('event', 'eggs_collected')->whereIn('subject_id', $collections->pluck('id'))->count());
        $this->assertSame(96, DB::table('activity_log')->where('event', 'daily_weighing_entry_added')->whereIn('subject_id', DailyWeighing::query()->where('flock_id', $flock->id)->pluck('id'))->count());
        $auditCount = DB::table('activity_log')->count();
        $this->assertGreaterThan($previousAuditCount, $auditCount);

        // Mutación: al repetir otro día conserva fechas, operaciones, saldo y auditoría.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Montevideo'));
        $this->seed(EggHistoryDemoSeeder::class);
        $this->assertSame($collections->toArray(), EggCollection::query()->where('flock_id', $flock->id)->orderBy('occurred_at')->get()->toArray());
        $this->assertSame($balance->on_hand_quantity, $balance->fresh()->on_hand_quantity);
        $this->assertSame(229, $flock->fresh()->current_quantity);
        $this->assertSame(96, DailyWeighingEntry::query()->whereHas('dailyWeighing', fn ($query) => $query->where('flock_id', $flock->id))->count());
        $this->assertSame($auditCount, DB::table('activity_log')->count());
        $this->assertSame('2026-10-14 12:00:00', now('America/Montevideo')->format('Y-m-d H:i:s'));
    }

    // Flujo: evita sembrar el historial en producción sin la opción de demo.
    public function test_seeder_does_not_create_a_flock_in_production_without_demo_enabled(): void
    {
        // Preparación: simula producción sin cargar dependencias demo.
        $this->app->instance('env', 'production');
        config(['app.demo_db_reset_enabled' => false]);

        // Mutación: la carga sale antes de crear el lote o sus operaciones.
        $this->app->call([new EggHistoryDemoSeeder, 'run']);
        $this->assertDatabaseMissing('flocks', ['code' => 'LOT-EGG-TEST']);
    }
}
