<?php

namespace Tests\Feature\Lots;

use App\Actions\Lots\RecordWeighingAction;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class WeighingConcurrencyTest extends TestCase
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
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=pgsql');
        putenv('DB_HOST=postgres');
        putenv('DB_DATABASE='.DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
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

    /** Las réplicas necesitan una base PostgreSQL de pruebas completamente migrada. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }

        $this->migrateIsolatedDatabase();
    }

    // Flujo: dos reintentos simultáneos con la misma clave producen una sola operación de pesaje.
    public function test_concurrent_duplicate_keys_produce_exactly_one_operation(): void
    {
        // Preparación: crea el operador autorizado y un lote con su movimiento histórico visible.
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('weighings.manage', 'web'));
        $flock = Flock::factory()->create(['initial_quantity' => 20, 'current_quantity' => 20]);
        FlockMovement::factory()->create([
            'destination_flock_id' => $flock->id,
            'quantity' => 20,
            'occurred_at' => $flock->established_at,
            'created_by' => $actor->id,
            'after' => [$flock->public_id => [
                'public_id' => $flock->public_id,
                'poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id,
                'current_quantity' => 20,
                'entry_date' => $flock->entry_date->format('Y-m-d'),
            ]],
        ]);
        $actorId = $actor->id;
        $flockId = $flock->id;
        $data = [
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
            'idempotency_key' => (string) Str::uuid(),
        ];

        // Mutación: ejecuta dos procesos que presentan exactamente el mismo comando.
        $task = static function () use ($actorId, $flockId, $data): string {
            if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
                throw new \RuntimeException('El proceso secundario debe usar la base de pruebas exclusiva.');
            }

            return app(RecordWeighingAction::class)->execute(
                Flock::query()->findOrFail($flockId),
                $data,
                User::query()->findOrFail($actorId),
            )->operation_id;
        };
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);

        // Verificación: ambos procesos reciben el mismo identificador y no duplican ningún efecto.
        $this->assertCount(2, $results);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('weighings', 1);
        $this->assertDatabaseCount('weighing_measurements', 1);
        $this->assertDatabaseCount('flock_operations', 1);
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'weighing_recorded']);
    }
}
