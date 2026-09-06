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
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateIsolatedDatabase;
    }

    /** Los procesos secundarios necesitan datos confirmados en una base exclusiva de pruebas. */
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql' || DB::connection()->getDatabaseName() !== 'gam_medicines_test') {
            $this->markTestSkipped('Ejecutar con phpunit.medicines-concurrency.xml y la base gam_medicines_test.');
        }
        $this->migrateIsolatedDatabase();
    }

    // Flujo: dos procesos con la misma clave devuelven una sola alta auditada.
    public function test_concurrent_replays_create_one_medicine_and_one_audit(): void
    {
        // Preparación: confirma actor, proveedor y comando compartidos entre procesos.
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $actorId = $admin->id;
        $data = ['name' => 'Medicamento concurrente', 'description' => 'Ficha de prueba',
            'supplier_id' => Supplier::factory()->create()->id, 'idempotency_key' => (string) Str::uuid()];
        $task = static function () use ($actorId, $data): string {
            if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'gam_medicines_test') {
                throw new \RuntimeException('El proceso secundario debe usar la base de pruebas exclusiva.');
            }

            return app(CreateMedicineAction::class)->execute($data, User::query()->findOrFail($actorId))->operation_id;
        };

        // Acción: ejecuta ambos procesos con PostgreSQL real y comprueba exclusión.
        $results = Concurrency::driver('process')->run([$task, $task], timeout: 30);
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('medicines', 1);
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', ['operation_id' => $results[0], 'event' => 'medicine_created']);
    }
}
