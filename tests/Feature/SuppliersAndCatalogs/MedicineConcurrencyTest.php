<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateMedicineAction;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

#[Group('medicine-concurrency')]
final class MedicineConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string, string|false> Valores del proceso que deben restaurarse al cerrar la prueba. */
    private array $originalProcessEnvironment = [];

    /** @var array<string, string|null> Valores de entorno de PHP previos a la prueba. */
    private array $originalEnvEnvironment = [];

    /** @var array<string, string|null> Valores del servidor de PHP previos a la prueba. */
    private array $originalServerEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Preparación: comparte la clave de firma y la conexión aislada con los procesos secundarios.
        $processEnvironment = [
            'APP_KEY' => (string) config('app.key'),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
        ];
        foreach ($processEnvironment as $key => $value) {
            $this->originalProcessEnvironment[$key] = getenv($key);
            $this->originalEnvEnvironment[$key] = $_ENV[$key] ?? null;
            $this->originalServerEnvironment[$key] = $_SERVER[$key] ?? null;
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        // Limpieza: evita propagar la configuración particular hacia otras pruebas.
        foreach ($this->originalProcessEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
            if ($this->originalEnvEnvironment[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->originalEnvEnvironment[$key];
            }
            if ($this->originalServerEnvironment[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->originalServerEnvironment[$key];
            }
        }

        parent::tearDown();
    }

    /** Los procesos secundarios necesitan datos confirmados en una base exclusiva de pruebas. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('La concurrencia real requiere una base PostgreSQL aislada con sufijo _testing.');
        }
        $this->migrateIsolatedDatabase();
    }

    // Flujo: dos procesos con la misma clave crean una ficha, un producto y sus dos eventos auditados.
    public function test_concurrent_replays_create_one_medicine_and_one_product_link(): void
    {
        // Preparación: confirma actor, proveedor y comando compartidos entre procesos.
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $actorId = $admin->id;
        $data = ['name' => 'Medicamento concurrente', 'description' => 'Ficha de prueba',
            'supplier_id' => Supplier::factory()->create()->id, 'idempotency_key' => (string) Str::uuid()];
        $task = static function () use ($actorId, $data): string {
            if (! app()->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
                throw new \RuntimeException('El proceso secundario debe usar la base de pruebas exclusiva.');
            }

            return app(CreateMedicineAction::class)->execute($data, User::query()->findOrFail($actorId))->operation_id;
        };

        // Acción: ejecuta ambos procesos con PostgreSQL real y comprueba exclusión.
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('medicines', 1);
        $this->assertDatabaseCount('activity_log', 2);
        $this->assertDatabaseHas('activity_log', ['operation_id' => $results[0], 'event' => 'medicine_created']);
    }
}
