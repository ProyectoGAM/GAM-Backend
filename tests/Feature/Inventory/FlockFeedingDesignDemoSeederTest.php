<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedConsumptionCorrection;
use App\Models\Inventory\FeedConsumptionMovement;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\ManagementPlans\RationChange;
use App\Models\ManagementPlans\VaccinationApplication;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Inventory\FlockFeedingDesignDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FlockFeedingDesignDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea el lote con manejos vinculados y consumos reales sin duplicar historia al repetir otro día.
    public function test_local_fixture_has_management_and_feeding_records_and_preserves_them_on_retry(): void
    {
        // Preparación: carga las dependencias demo en una base aislada y fija el reloj local.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Montevideo'));
        $this->app->instance('env', 'local');
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $beforeBalance = FeedBalance::query()->sum('quantity_grams');

        // Mutación: ejecuta el fixture completo con las Actions y auditorías de negocio.
        $this->seed(FlockFeedingDesignDemoSeeder::class);
        $flock = Flock::query()->where('code', 'LOT-FEED-TEST')->firstOrFail();
        $this->assertSame(120, $flock->current_quantity);
        $this->assertSame('2026-10-07', $flock->entry_date->toDateString());
        $this->assertSame('2026-10-14 12:00', now('America/Montevideo')->format('Y-m-d H:i'));
        $this->assertDatabaseHas('poultry_houses', ['id' => $flock->poultry_house_id, 'bird_capacity' => 120]);
        $plan = FlockPlan::query()->where('flock_id', $flock->id)->firstOrFail();
        $activities = FlockPlanActivity::query()->whereHas('revision', fn ($query) => $query->where('flock_plan_id', $plan->id));
        $this->assertSame(6, $activities->count());
        $this->assertSame(3, $activities->where('type', 'ration_change')->count());
        $this->assertSame(2, ManualPractice::query()->where('flock_id', $flock->id)->whereNotNull('plan_activity_id')->count());
        $this->assertSame(1, VaccinationApplication::query()->where('flock_id', $flock->id)->whereNotNull('plan_activity_id')->count());
        $this->assertSame(1, RationChange::query()->where('flock_id', $flock->id)->whereNotNull('plan_activity_id')->count());
        $this->assertSame(['automatic', 'manual', 'automatic'], FlockFeedingChange::query()->where('flock_id', $flock->id)->orderBy('id')->pluck('mode')->all());

        // Consulta: comprueba cada jornada, la compensación y el débito neto de 45500 gramos.
        $consumptions = FeedConsumption::query()->where('flock_id', $flock->id)->orderBy('consumption_date')->get();
        $this->assertSame([4800, 4800, 4800, 7200, 8500, 8500, 7200], $consumptions->pluck('quantity_grams')->all());
        $corrected = $consumptions->firstWhere('quantity_grams', 8500);
        $this->assertSame(8500, $corrected->effective()['quantity_grams']);
        $this->assertDatabaseHas('feed_consumption_corrections', ['feed_consumption_id' => $consumptions->get(5)->id, 'quantity_grams' => 8200]);
        $this->assertSame(8, FeedConsumptionMovement::query()->whereIn('feed_consumption_id', $consumptions->modelKeys())->count());
        $this->assertSame(45500, (int) $beforeBalance - (int) FeedBalance::query()->sum('quantity_grams'));
        $this->assertSame(7, DB::table('activity_log')->where('event', 'feed_consumption_recorded')->whereIn('subject_id', $consumptions->modelKeys())->count());

        // Mutación: repetir otro día conserva saldos, auditoría y registros modificables por el usuario.
        $before = [
            FeedConsumption::query()->get()->toArray(), FeedConsumptionCorrection::query()->get()->toArray(),
            FeedConsumptionMovement::query()->get()->toArray(), FlockFeedingChange::query()->get()->toArray(),
            FeedBalance::query()->get()->toArray(), DB::table('activity_log')->count(),
            ManualPractice::query()->count(), RationChange::query()->count(), VaccinationApplication::query()->count(),
        ];
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'America/Montevideo'));
        $this->seed(FlockFeedingDesignDemoSeeder::class);
        $this->assertSame($before, [
            FeedConsumption::query()->get()->toArray(), FeedConsumptionCorrection::query()->get()->toArray(),
            FeedConsumptionMovement::query()->get()->toArray(), FlockFeedingChange::query()->get()->toArray(),
            FeedBalance::query()->get()->toArray(), DB::table('activity_log')->count(),
            ManualPractice::query()->count(), RationChange::query()->count(), VaccinationApplication::query()->count(),
        ]);
        $this->assertSame(1, Flock::query()->where('code', 'LOT-FEED-TEST')->count());
    }

    // Flujo: el fixture optativo no modifica producción aunque esté habilitada la demo de despliegue.
    public function test_fixture_is_skipped_outside_local(): void
    {
        // Preparación: simula producción sin dependencias y con la opción de demo activa.
        $this->assertDatabaseCount('flocks', 0);
        $this->app->instance('env', 'production');
        config(['app.demo_db_reset_enabled' => true]);

        // Mutación: invoca el seeder con sus dependencias sin la confirmación de Artisan en producción.
        $this->app->call([new FlockFeedingDesignDemoSeeder, 'run']);
        $this->assertDatabaseMissing('flocks', ['code' => 'LOT-FEED-TEST']);
        $this->assertDatabaseCount('flock_feeding_changes', 0);
    }
}
