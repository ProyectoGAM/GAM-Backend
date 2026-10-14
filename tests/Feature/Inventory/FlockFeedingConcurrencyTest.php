<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\Actions\ManagementPlans\AssignFlockPlanAction;
use App\Models\Inventory\FeedConsumption;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FlockFeedingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string,string|false> */
    private array $originalEnvironment = [];

    /** @var array<string,mixed> */
    private array $originalEnvValues = [];

    /** @var array<string,mixed> */
    private array $originalServerValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'APP_KEY' => config('app.key'), 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'postgres', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
        ] as $name => $value) {
            $this->originalEnvironment[$name] = getenv($name);
            $this->originalEnvValues[$name] = $_ENV[$name] ?? false;
            $this->originalServerValues[$name] = $_SERVER[$name] ?? false;
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
            if ($this->originalEnvValues[$name] === false) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $this->originalEnvValues[$name];
            }
            if ($this->originalServerValues[$name] === false) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $this->originalServerValues[$name];
            }
        }
        parent::tearDown();
    }

    /** Sólo permite reconstruir PostgreSQL con el sufijo de aislamiento obligatorio. */
    public function runDatabaseMigrations(): void
    {
        $this->migrateIsolatedDatabase();
    }

    /** @return array{flock:Flock, actor:User, date:string} */
    private function fixture(): array
    {
        $date = CarbonImmutable::now('America/Montevideo')->subDay()->setTime(12, 0);
        $this->travelTo($date);
        $actor = User::factory()->create();
        $template = PlanTemplate::factory()->published()->create(['created_by' => $actor->id]);
        $flock = Flock::factory()->create([
            'initial_quantity' => 100, 'current_quantity' => 100,
            'entry_date' => $date->toDateString(), 'established_at' => $date->startOfDay()->utc(),
        ]);
        DB::transaction(fn () => app(AssignFlockPlanAction::class)->assignPublished($flock, $template->public_id, 1, $actor, (string) Str::uuid()));
        $this->travelBack();

        return ['flock' => $flock, 'actor' => $actor, 'date' => $date->toDateString()];
    }

    // Flujo: dos procesos compiten por la misma jornada y producen un solo descuento.
    public function test_concurrent_daily_retries_have_one_logical_record_and_one_stock_debit(): void
    {
        // Preparación: comparte una jornada cerrada entre procesos.
        $fixture = $this->fixture();
        $id = $fixture['flock']->id;
        $date = $fixture['date'];

        // Mutación: el bloqueo del lote serializa las inserciones concurrentes.
        $task = static fn (): int => app(RecordDailyFeedConsumptionAction::class)->execute($id, $date)->id;
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('feed_consumptions', 1);
        $this->assertDatabaseCount('feed_consumption_movements', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => -4000]);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'feed_consumption_recorded')->count());
    }

    // Flujo: consumos de lotes diferentes comparten saldo sin perder actualizaciones.
    public function test_concurrent_flocks_accumulate_debits_in_the_same_recipe_and_unit(): void
    {
        // Preparación: asigna el mismo plan y receta a dos lotes de la misma UP.
        $fixture = $this->fixture();
        $firstId = $fixture['flock']->id;
        $date = $fixture['date'];
        $this->travelTo(CarbonImmutable::parse($date, 'America/Montevideo')->setTime(12, 0));
        $second = Flock::factory()->create([
            'initial_quantity' => 100, 'current_quantity' => 100,
            'production_unit_id' => $fixture['flock']->production_unit_id,
            'entry_date' => $date, 'established_at' => CarbonImmutable::parse($date, 'America/Montevideo')->utc(),
        ]);
        $template = PlanTemplate::query()->sole();
        DB::transaction(fn () => app(AssignFlockPlanAction::class)->assignPublished($second, $template->public_id, 1, $fixture['actor'], (string) Str::uuid()));
        $secondId = $second->id;
        $this->travelBack();

        // Mutación: ambos descuentos se aplican en el saldo compartido.
        Concurrency::driver('process')->run([
            static fn (): int => app(RecordDailyFeedConsumptionAction::class)->execute($firstId, $date)->id,
            static fn (): int => app(RecordDailyFeedConsumptionAction::class)->execute($secondId, $date)->id,
        ], timeout: 30);
        $this->assertDatabaseCount('feed_consumptions', 2);
        $this->assertDatabaseCount('feed_balances', 1);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => -8000]);
    }

    // Flujo: la rectificación repetida simultáneamente compensa sólo una vez.
    public function test_concurrent_correction_retries_only_apply_one_compensation(): void
    {
        // Preparación: registra el original y prepara la misma clave para dos procesos.
        $fixture = $this->fixture();
        $consumptionId = app(RecordDailyFeedConsumptionAction::class)->execute($fixture['flock']->id, $fixture['date'])->id;
        $actorId = $fixture['actor']->id;
        $key = (string) Str::uuid();

        // Mutación: ambos procesos rectifican a mil gramos.
        $task = static fn (): string => app(CorrectFeedConsumptionAction::class)->execute(
            FeedConsumption::query()->findOrFail($consumptionId),
            ['cantidad_g' => '1000', 'motivo' => 'Recuento', 'idempotency_key' => $key],
            User::query()->findOrFail($actorId),
        )->operation_id;
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('feed_consumption_corrections', 1);
        $this->assertDatabaseCount('feed_consumption_movements', 2);
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => -1000]);
    }

    // Flujo: rectificaciones distintas y concurrentes parten del último resultado efectivo.
    public function test_different_concurrent_corrections_preserve_each_compensation_and_latest_effective_total(): void
    {
        // Preparación: comparte el consumo original entre dos actores.
        $fixture = $this->fixture();
        $consumptionId = app(RecordDailyFeedConsumptionAction::class)->execute($fixture['flock']->id, $fixture['date'])->id;
        $firstActorId = $fixture['actor']->id;
        $secondActorId = User::factory()->create()->id;

        // Mutación: ambas correcciones quedan anexadas y el saldo coincide con la última.
        Concurrency::driver('process')->run([
            static fn (): string => app(CorrectFeedConsumptionAction::class)->execute(FeedConsumption::query()->findOrFail($consumptionId),
                ['cantidad_g' => '1000', 'motivo' => 'Primer recuento', 'idempotency_key' => (string) Str::uuid()], User::query()->findOrFail($firstActorId))->operation_id,
            static fn (): string => app(CorrectFeedConsumptionAction::class)->execute(FeedConsumption::query()->findOrFail($consumptionId),
                ['cantidad_g' => '2000', 'motivo' => 'Segundo recuento', 'idempotency_key' => (string) Str::uuid()], User::query()->findOrFail($secondActorId))->operation_id,
        ], timeout: 30);
        $effective = FeedConsumption::query()->findOrFail($consumptionId)->effective();
        $this->assertDatabaseHas('feed_balances', ['quantity_grams' => -$effective['quantity_grams']]);
        $this->assertDatabaseCount('feed_consumption_corrections', 2);
        $this->assertDatabaseCount('feed_consumption_movements', 3);
        $this->assertSame(4000, (int) json_decode(DB::table('feed_consumption_corrections')->orderBy('id')->value('before_snapshot'), true)['quantity_grams']);
    }
}
