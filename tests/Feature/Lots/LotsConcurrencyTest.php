<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityCategory;
use App\Models\User;
use App\Modules\Lots\Application\Actions\CreateFlockAction;
use App\Modules\Lots\Application\Actions\RecordMortalityAction;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class LotsConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateIsolatedDatabase;
    }

    /** Los procesos secundarios necesitan datos confirmados, nunca la base de desarrollo. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || DB::connection()->getDatabaseName() !== 'gam_lots_test') {
            $this->markTestSkipped('La concurrencia real requiere la base PostgreSQL aislada gam_lots_test.');
        }
        $this->migrateIsolatedDatabase();
    }

    private function actor(string $permission): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));

        return $actor;
    }

    // Flujo: dos procesos compiten por las mismas plazas sin superar capacidad física.
    public function test_concurrent_admissions_cannot_overbook_the_same_house(): void
    {
        // Preparación: confirma referencias compartidas y dos usuarios diferentes.
        $house = PoultryHouse::factory()->create(['bird_capacity' => 100]);
        $breed = Breed::factory()->create();
        $commands = [];
        foreach ([1, 2] as $number) {
            $commands[] = [
                'actor_id' => $this->actor('flocks.manage')->id,
                'data' => ['code' => 'CONCURRENT-'.$number, 'breed_id' => $breed->id, 'origin' => 'Propio',
                    'poultry_house_id' => $house->id, 'initial_quantity' => 70,
                    'entry_date' => now(config('lots.timezone'))->subDay()->toDateString(), 'idempotency_key' => (string) Str::uuid()],
            ];
        }

        // Mutación: ejecuta simultáneamente dos admisiones que no caben juntas.
        $tasks = [];
        foreach ($commands as $command) {
            $tasks[] = static function () use ($command): string {
                try {
                    app(CreateFlockAction::class)->execute($command['data'], User::query()->findOrFail($command['actor_id']));

                    return 'created';
                } catch (LotsConflict) {
                    return 'conflict';
                }
            };
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 30);
        sort($results);
        $this->assertSame(['conflict', 'created'], $results);
        $this->assertSame(70, (int) Flock::query()->sum('current_quantity'));
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertDatabaseCount('flock_operations', 1);
    }

    // Flujo: escrituras simultáneas con la misma versión no descuentan dos veces las aves.
    public function test_concurrent_mortality_commands_have_one_version_winner(): void
    {
        // Preparación: confirma un lote de diez aves y dos operadores independientes.
        $flock = Flock::factory()->create(['initial_quantity' => 10, 'current_quantity' => 10]);
        $category = MortalityCategory::factory()->create();
        $commands = [];
        foreach ([1, 2] as $number) {
            $commands[] = ['flock_id' => $flock->id, 'actor_id' => $this->actor('mortality.manage')->id,
                'data' => ['version' => 1, 'quantity' => 7, 'mortality_category_id' => $category->id, 'idempotency_key' => (string) Str::uuid()]];
        }

        // Mutación: ambos procesos intentan descontar siete aves con la misma versión.
        $tasks = [];
        foreach ($commands as $command) {
            $tasks[] = static function () use ($command): string {
                try {
                    app(RecordMortalityAction::class)->execute(Flock::query()->findOrFail($command['flock_id']), $command['data'], User::query()->findOrFail($command['actor_id']));

                    return 'recorded';
                } catch (LotsConflict) {
                    return 'conflict';
                }
            };
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 30);
        sort($results);
        $this->assertSame(['conflict', 'recorded'], $results);
        $this->assertSame(3, $flock->fresh()->current_quantity);
        $this->assertSame(2, $flock->fresh()->version);
        $this->assertDatabaseCount('mortality_records', 1);
    }

    // Flujo: dos reintentos concurrentes del mismo actor reciben la misma operación.
    public function test_concurrent_duplicate_keys_produce_exactly_one_operation(): void
    {
        // Preparación: utiliza un único actor y exactamente el mismo comando.
        $actorId = $this->actor('flocks.manage')->id;
        $data = ['code' => 'SAME-KEY', 'breed_id' => Breed::factory()->create()->id, 'origin' => 'Propio',
            'poultry_house_id' => PoultryHouse::factory()->create(['bird_capacity' => 10])->id, 'initial_quantity' => 10,
            'entry_date' => now(config('lots.timezone'))->subDay()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $task = static function () use ($actorId, $data): string {
            return app(CreateFlockAction::class)->execute($data, User::query()->findOrFail($actorId))->operation_id;
        };

        // Mutación: confirma que la exclusión por actor resuelve el segundo intento como replay.
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('flocks', 1);
        $this->assertDatabaseCount('flock_operations', 1);
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }
}
