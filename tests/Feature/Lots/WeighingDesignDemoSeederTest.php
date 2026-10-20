<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\Flock;
use App\ValueObjects\Lots\FlockAge;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Lots\WeighingDesignDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WeighingDesignDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea el lote de 60 semanas con jornadas mixtas, anomalías y auditoría sin duplicar datos.
    public function test_local_design_seeder_creates_repeatable_daily_weighing_sample(): void
    {
        // Preparación: carga los catálogos, el plan y las instalaciones demo en una fecha fija.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'America/Montevideo'));
        $this->app->instance('env', 'local');
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        // Mutación: siembra el lote y sus ingresos a través de las Actions reales.
        $this->seed(WeighingDesignDemoSeeder::class);
        $flock = Flock::query()->where('code', 'LOT-PESAJE-TEST')->firstOrFail();
        $this->assertSame(60, FlockAge::on($flock->entry_date->toDateString(), CarbonImmutable::now(), config('lots.timezone'))->week);
        $this->assertSame(120, $flock->current_quantity);
        $this->assertSame(6, DailyWeighing::query()->where('flock_id', $flock->id)->count());
        $this->assertSame(72, DailyWeighingEntry::query()->whereHas('dailyWeighing', fn ($query) => $query->where('flock_id', $flock->id))->count());
        $this->assertSame(7, DailyWeighingEntry::query()->whereHas('dailyWeighing', fn ($query) => $query->where('flock_id', $flock->id))->where('outside_expected_range', true)->count());
        $this->assertSame(12, DailyWeighingEntry::query()->whereHas('dailyWeighing', fn ($query) => $query->where('flock_id', $flock->id))->where('mode', 'group')->count());
        $today = DailyWeighing::query()->where('flock_id', $flock->id)->whereDate('local_date', '2026-10-07')->firstOrFail();
        $this->assertSame(22, $today->represented_bird_count);
        $this->assertSame(2, $today->anomalous_entry_count);
        $this->assertSame(72, DB::table('activity_log')->where('event', 'daily_weighing_entry_added')->where('source', 'seeder')->count());

        // Mutación: repetir la carga conserva las mismas jornadas e ingresos.
        $before = DailyWeighing::query()->where('flock_id', $flock->id)->orderBy('id')->get()->toArray();
        $this->seed(WeighingDesignDemoSeeder::class);
        $this->assertSame($before, DailyWeighing::query()->where('flock_id', $flock->id)->orderBy('id')->get()->toArray());
        $this->assertSame(72, DailyWeighingEntry::query()->whereHas('dailyWeighing', fn ($query) => $query->where('flock_id', $flock->id))->count());
    }

    // Flujo: el fixture de diseño queda limitado al entorno local.
    public function test_design_seeder_does_not_create_a_lot_outside_local(): void
    {
        // Preparación: usa la base aislada sin datos demo y simula producción.
        $this->app->instance('env', 'production');

        // Mutación: el seeder sale antes de buscar dependencias o crear registros.
        $this->app->call([new WeighingDesignDemoSeeder, 'run']);
        $this->assertDatabaseMissing('flocks', ['code' => 'LOT-PESAJE-TEST']);
    }

    // Flujo: crea un galpón operativo cuando todos los existentes están ocupados o indisponibles.
    public function test_design_seeder_creates_house_when_none_is_available(): void
    {
        // Preparación: carga la demo y retira de servicio los galpones que quedaron libres.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'America/Montevideo'));
        $this->app->instance('env', 'local');
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $occupiedIds = Flock::query()->whereIn('status', ['active', 'quarantined'])->pluck('poultry_house_id');
        PoultryHouse::query()->whereNotIn('id', $occupiedIds)->where('type', 'poultry')->update(['status' => 'maintenance']);

        // Mutación: la carga crea una instalación apta y aloja allí el lote.
        $this->seed(WeighingDesignDemoSeeder::class);
        $flock = Flock::query()->where('code', 'LOT-PESAJE-TEST')->firstOrFail();
        $house = PoultryHouse::query()->findOrFail($flock->poultry_house_id);
        $this->assertSame('Galpón Pesaje Test', $house->name);
        $this->assertSame(120, $house->bird_capacity);
        $this->assertSame(6, DailyWeighing::query()->where('flock_id', $flock->id)->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'poultry_house_created')->where('subject_id', $house->id)->count());
    }
}
