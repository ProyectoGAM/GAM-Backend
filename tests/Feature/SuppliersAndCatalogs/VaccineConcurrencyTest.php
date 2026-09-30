<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Actions\Inventory\ReceiveStockAction;
use App\Actions\SuppliersAndCatalogs\ChangeVaccineStatusAction;
use App\Actions\SuppliersAndCatalogs\CreateVaccineAction;
use App\Actions\SuppliersAndCatalogs\UpdateProductAction;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Exceptions\SuppliersAndCatalogs\SuppliersAndCatalogsConflict;
use App\Exceptions\SuppliersAndCatalogs\VaccineConflict;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Console\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

#[Group('vaccine-concurrency')]
final class VaccineConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateIsolatedDatabase;
    }

    private ?string $originalAppKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppKey = getenv('APP_KEY') === false ? null : getenv('APP_KEY');
        putenv('APP_KEY='.config('app.key'));
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

        parent::tearDown();
    }

    /** Los procesos secundarios requieren PostgreSQL y una base aislada. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }
        $this->migrateIsolatedDatabase();
    }

    // Flujo: serializa reintentos concurrentes del mismo actor y clave de alta.
    public function test_concurrent_replays_create_one_vaccine_and_one_audit(): void
    {
        // Preparación: confirma actor, proveedor y comando compartidos.
        $actor = $this->admin();
        $supplier = Supplier::factory()->create();
        $actorId = $actor->id;
        $data = ['sku' => 'VAC-CONC-01', 'name' => 'Vacuna concurrente', 'description' => 'Ficha concurrente', 'supplier_id' => $supplier->id, 'idempotency_key' => (string) Str::uuid()];
        [$userLocked, $secondAttempt, $secondProcess] = $this->createLockSignals();

        [$firstReady, $secondReady] = $this->createBarrierSignals();

        try {
            // Acción: observa el FOR UPDATE real del actor y espera a que el segundo proceso quede bloqueado.
            $firstTask = static function () use ($actorId, $data, $firstReady, $secondReady, $userLocked, $secondAttempt, $secondProcess): string {
                self::assertTestingDatabase();
                self::signal($firstReady);
                self::waitForSignal($secondReady);

                $assertUserLock = self::assertRealForUpdateLock('users', $actorId, $userLocked, $secondAttempt, $secondProcess);

                try {
                    return app(CreateVaccineAction::class)->execute($data, User::query()->findOrFail($actorId))->operation_id;
                } finally {
                    $assertUserLock();
                }
            };
            $secondTask = static function () use ($actorId, $data, $firstReady, $secondReady, $userLocked, $secondAttempt, $secondProcess): string {
                self::assertTestingDatabase();
                self::signal($secondReady);
                self::waitForSignal($firstReady);
                self::waitForSignal($userLocked);
                self::signal($secondProcess, (string) self::currentBackendProcessId());
                self::signal($secondAttempt);

                return app(CreateVaccineAction::class)->execute($data, User::query()->findOrFail($actorId))->operation_id;
            };
            $results = $this->runTasks([$firstTask, $secondTask]);
        } finally {
            $this->dropBarrierTable();
        }

        // Verificación: ambos procesos observan la misma alta sin duplicados.
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('vaccines', 1);
        $this->assertDatabaseCount('activity_log', 2);
    }

    // Flujo: coordina un ingreso con la baja para impedir estado inactivo con saldo nuevo.
    public function test_movement_and_inactivation_cannot_both_succeed_concurrently(): void
    {
        // Preparación: crea vacuna, proveedor, ubicación y actor compartidos.
        $actor = $this->admin();
        $supplier = Supplier::factory()->create();
        $vaccine = Vaccine::factory()->for($supplier)->create();
        $location = StockLocation::factory()->create();
        $vaccineId = $vaccine->id;
        $actorId = $actor->id;
        $supplierId = $supplier->id;
        $productId = $vaccine->product_id;
        $locationId = $location->id;
        [$movementLocked, $statusReady] = $this->createBarrierSignals();

        try {
            // Acción: pausa tras observar el FOR SHARE real del ingreso hasta que la baja intente el lock exclusivo.
            $movementTask = static function () use ($actorId, $supplierId, $productId, $locationId, $movementLocked, $statusReady): string {
                self::assertTestingDatabase();
                $assertSharedLock = self::assertRealProductSharedLock($productId, $movementLocked, $statusReady);

                try {
                    app(ReceiveStockAction::class)->execute(['supplier_id' => $supplierId, 'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $productId, 'stock_location_id' => $locationId, 'quantity' => '1']]], User::query()->findOrFail($actorId));

                    return 'movement';
                } catch (InventoryConflict) {
                    return 'movement_conflict';
                } finally {
                    $assertSharedLock();
                }
            };
            $statusTask = static function () use ($actorId, $vaccineId, $movementLocked, $statusReady): string {
                self::assertTestingDatabase();
                self::waitForSignal($movementLocked);
                self::signal($statusReady);

                try {
                    app(ChangeVaccineStatusAction::class)->execute(Vaccine::query()->findOrFail($vaccineId), ProductStatus::Inactive, User::query()->findOrFail($actorId));

                    return 'status';
                } catch (SuppliersAndCatalogsConflict) {
                    return 'status_conflict';
                }
            };
            $results = $this->runTasks([$movementTask, $statusTask]);
        } finally {
            $this->dropBarrierTable();
        }

        // Verificación: el ingreso confirma primero y la baja bloqueada rechaza el saldo recién creado.
        $this->assertSame(['movement', 'status_conflict'], $results);
        $this->assertSame('active', Vaccine::query()->findOrFail($vaccineId)->product->status->value);
        $this->assertSame(1, DB::table('inventory_movements')->count());
        $this->assertSame('1.000000', DB::table('stock_balances')->where('product_id', $productId)->value('on_hand_quantity'));
    }

    // Flujo: permite una sola ficha cuando dos actores asocian el mismo SKU.
    public function test_two_actors_can_associate_a_sku_only_once(): void
    {
        // Preparación: crea el producto vacuna y dos administradores con claves distintas.
        $firstActor = $this->admin();
        $secondActor = $this->admin();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->vaccine()->create(['sku' => 'VAC-SKU-RACE', 'name' => 'Vacuna SKU concurrente']);
        $firstPayload = ['sku' => $product->sku, 'name' => $product->name, 'description' => 'Ficha uno', 'supplier_id' => $supplier->id, 'idempotency_key' => (string) Str::uuid()];
        $secondPayload = [...$firstPayload, 'description' => 'Ficha dos', 'idempotency_key' => (string) Str::uuid()];
        $firstActorId = $firstActor->id;
        $secondActorId = $secondActor->id;
        [$productLocked, $secondAttempt, $secondProcess] = $this->createLockSignals();
        [$firstReady, $secondReady] = $this->createBarrierSignals();

        try {
            // Acción: observa el FOR UPDATE real del producto y fuerza que el segundo actor espere ese lock.
            $firstTask = static function () use ($firstActorId, $firstPayload, $firstReady, $secondReady, $product, $productLocked, $secondAttempt, $secondProcess): string {
                self::assertTestingDatabase();
                self::signal($firstReady);
                self::waitForSignal($secondReady);
                $assertProductLock = self::assertRealForUpdateLock('products', $product->sku, $productLocked, $secondAttempt, $secondProcess);

                try {
                    app(CreateVaccineAction::class)->execute($firstPayload, User::query()->findOrFail($firstActorId));

                    return 'created';
                } finally {
                    $assertProductLock();
                }
            };
            $secondTask = static function () use ($secondActorId, $secondPayload, $firstReady, $secondReady, $productLocked, $secondAttempt, $secondProcess): string {
                self::assertTestingDatabase();
                self::signal($secondReady);
                self::waitForSignal($firstReady);
                self::waitForSignal($productLocked);
                self::signal($secondProcess, (string) self::currentBackendProcessId());
                self::signal($secondAttempt);

                try {
                    app(CreateVaccineAction::class)->execute($secondPayload, User::query()->findOrFail($secondActorId));

                    return 'created';
                } catch (VaccineConflict) {
                    return 'conflict';
                }
            };
            $results = $this->runTasks([
                $firstTask,
                $secondTask,
            ]);
        } finally {
            $this->dropBarrierTable();
        }

        // Verificación: persiste una ficha y un único evento de alta.
        $this->assertSame(['conflict', 'created'], collect($results)->sort()->values()->all());
        $this->assertDatabaseCount('vaccines', 1);
        $this->assertDatabaseCount('activity_log', 1);
    }

    // Flujo: serializa el primer movimiento frente a un cambio de unidad incompatible.
    public function test_first_movement_and_unit_change_leave_no_fractional_unit_balance(): void
    {
        // Preparación: crea vacuna en kilogramos sin movimientos y una ubicación activa.
        $actor = $this->admin();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->vaccine()->create(['base_unit' => 'kg']);
        $vaccine = Vaccine::factory()->for($supplier)->create(['product_id' => $product->id]);
        $location = StockLocation::factory()->create();
        $actorId = $actor->id;
        $supplierId = $supplier->id;
        $productId = $product->id;
        $locationId = $location->id;
        [$movementLocked, $unitReady] = $this->createBarrierSignals();

        try {
            // Acción: pausa tras observar el FOR SHARE real del ingreso hasta que la unidad intente cambiarse.
            $movementTask = static function () use ($actorId, $supplierId, $productId, $locationId, $movementLocked, $unitReady): string {
                self::assertTestingDatabase();
                $assertSharedLock = self::assertRealProductSharedLock($productId, $movementLocked, $unitReady);

                try {
                    app(ReceiveStockAction::class)->execute(['supplier_id' => $supplierId, 'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $productId, 'stock_location_id' => $locationId, 'quantity' => '1.500000']]], User::query()->findOrFail($actorId));

                    return 'movement';
                } catch (InventoryConflict) {
                    return 'movement_conflict';
                } finally {
                    $assertSharedLock();
                }
            };
            $unitTask = static function () use ($actorId, $productId, $movementLocked, $unitReady): string {
                self::assertTestingDatabase();
                self::waitForSignal($movementLocked);
                self::signal($unitReady);

                try {
                    app(UpdateProductAction::class)->execute(Product::query()->findOrFail($productId), ['base_unit' => 'unit'], User::query()->findOrFail($actorId));

                    return 'unit';
                } catch (SuppliersAndCatalogsConflict) {
                    return 'unit_conflict';
                }
            };
            $results = $this->runTasks([$movementTask, $unitTask]);
        } finally {
            $this->dropBarrierTable();
        }

        // Verificación: el primer movimiento confirma y bloquea el cambio tardío de unidad.
        $this->assertSame(['movement', 'unit_conflict'], $results);
        $this->assertSame('kg', Product::query()->findOrFail($productId)->base_unit->value);
        $this->assertSame(1, DB::table('inventory_movements')->count());
        $this->assertSame('1.500000', DB::table('stock_balances')->where('product_id', $productId)->value('on_hand_quantity'));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
    }

    private static function assertTestingDatabase(): void
    {
        if (! app()->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            throw new \RuntimeException('El proceso secundario debe usar la base de pruebas exclusiva.');
        }
    }

    /** @return array{string, string} */
    private function createBarrierSignals(): array
    {
        Schema::dropIfExists('vaccine_test_barriers');
        Schema::create('vaccine_test_barriers', function (Blueprint $table): void {
            $table->string('token', 100)->primary();
            $table->string('value', 100)->nullable();
        });

        $prefix = (string) Str::uuid();

        return [$prefix.':movement_locked', $prefix.':competitor_ready'];
    }

    /** @return array{string, string, string} */
    private function createLockSignals(): array
    {
        $prefix = (string) Str::uuid();

        return [$prefix.':locked', $prefix.':second_attempt', $prefix.':second_process'];
    }

    private function dropBarrierTable(): void
    {
        Schema::dropIfExists('vaccine_test_barriers');
    }

    private static function signal(string $token, ?string $value = null): void
    {
        self::signalConnection()->table('vaccine_test_barriers')->updateOrInsert(['token' => $token], ['value' => $value]);
    }

    private static function waitForSignal(string $token): void
    {
        for ($attempt = 0; $attempt < 500; $attempt++) {
            if (self::signalConnection()->table('vaccine_test_barriers')->where('token', $token)->exists()) {
                return;
            }

            usleep(10_000);
        }

        throw new \RuntimeException('La barrera de concurrencia agotó el tiempo de espera.');
    }

    private static function waitForSignalValue(string $token): string
    {
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $value = self::signalConnection()->table('vaccine_test_barriers')->where('token', $token)->value('value');
            if (is_string($value) && $value !== '') {
                return $value;
            }

            usleep(10_000);
        }

        throw new \RuntimeException('La barrera de concurrencia no recibió el proceso esperado.');
    }

    private static function signalConnection(): Connection
    {
        static $connection = null;

        if ($connection instanceof Connection) {
            return $connection;
        }

        config()->set('database.connections.vaccine_test_signal', config('database.connections.pgsql'));

        return $connection = DB::connection('vaccine_test_signal');
    }

    private static function currentBackendProcessId(): int
    {
        $row = DB::selectOne('SELECT pg_backend_pid() AS process_id');

        return (int) $row->process_id;
    }

    private static function waitForBackendLock(int $processId): void
    {
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $waiting = self::signalConnection()->selectOne('SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = ? AND NOT granted) AS waiting', [$processId]);
            if ((bool) $waiting->waiting) {
                return;
            }

            usleep(10_000);
        }

        throw new \RuntimeException('El segundo proceso no quedó esperando el lock de PostgreSQL.');
    }

    /** @return \Closure(): void */
    private static function assertRealProductSharedLock(int $productId, string $lockedSignal, string $competitorSignal): \Closure
    {
        $observed = false;

        DB::listen(static function (QueryExecuted $query) use (&$observed, $productId, $lockedSignal, $competitorSignal): void {
            $sql = Str::lower($query->sql);
            $matchesProduct = str_contains($sql, 'from "products"') && str_contains($sql, 'for share');
            $matchesBinding = in_array((string) $productId, array_map(static fn (mixed $binding): string => (string) $binding, $query->bindings), true);
            if ($observed || ! $matchesProduct || ! $matchesBinding) {
                return;
            }

            $observed = true;
            self::signal($lockedSignal);
            self::waitForSignal($competitorSignal);
        });

        return static function () use (&$observed): void {
            if (! $observed) {
                throw new \RuntimeException('No se observó el FOR SHARE real del producto durante la operación.');
            }
        };
    }

    /** @param int|string $binding @return \Closure(): void */
    private static function assertRealForUpdateLock(string $table, int|string $binding, string $lockedSignal, string $secondAttemptSignal, string $secondProcessSignal): \Closure
    {
        $observed = false;

        DB::listen(static function (QueryExecuted $query) use (&$observed, $table, $binding, $lockedSignal, $secondAttemptSignal, $secondProcessSignal): void {
            $sql = Str::lower($query->sql);
            $matchesTable = str_contains($sql, 'from "'.Str::lower($table).'"') && str_contains($sql, 'for update');
            $matchesBinding = in_array((string) $binding, array_map(static fn (mixed $queryBinding): string => (string) $queryBinding, $query->bindings), true);
            if ($observed || ! $matchesTable || ! $matchesBinding) {
                return;
            }

            $observed = true;
            self::signal($lockedSignal);
            self::waitForSignal($secondAttemptSignal);
            self::waitForBackendLock((int) self::waitForSignalValue($secondProcessSignal));
        });

        return static function () use (&$observed): void {
            if (! $observed) {
                throw new \RuntimeException('No se observó el FOR UPDATE real durante la operación concurrente.');
            }
        };
    }

    /** @param list<\Closure> $tasks @return list<mixed> */
    private function runTasks(array $tasks): array
    {
        $command = Application::formatCommandString('invoke-serialized-closure');
        $environment = [
            'APP_KEY' => config('app.key'),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
        $results = app(ProcessFactory::class)->pool(function ($pool) use ($tasks, $command, $environment): void {
            foreach ($tasks as $key => $task) {
                $pool->as((string) $key)
                    ->path(base_path())
                    ->env([...$environment, 'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task)))])
                    ->timeout(30)
                    ->command($command);
            }
        })->start()->wait();

        return $results->collect()->map(function ($result): mixed {
            $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
            if (! $decoded['successful']) {
                throw new \RuntimeException((string) $decoded['message']);
            }

            return unserialize($decoded['result']);
        })->values()->all();
    }
}
