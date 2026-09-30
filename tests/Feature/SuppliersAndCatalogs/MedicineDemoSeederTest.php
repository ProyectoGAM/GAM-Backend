<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\SuppliersAndCatalogs\Medicine;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuppliersAndCatalogs\MedicineDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MedicineDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: la carga local integra dos fichas y sus reintentos no reescriben historia.
    public function test_local_seed_integrates_medicines_idempotently(): void
    {
        // Preparación: fija entorno y almacenamiento de las otras demos.
        $this->travelTo('2026-09-05T12:00:00+00:00');
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Acción: ejecuta la entrada oficial de datos demo.
        $this->seed(DatabaseSeeder::class);
        $before = Medicine::query()->orderBy('id')->get()->toArray();
        $this->assertCount(2, $before);
        $this->assertSame(2, AuditEntry::query()->where('event', 'medicine_created')->where('source', 'seeder')->count());

        // Acción: repite la carga del módulo en otro momento.
        $this->travel(1)->days();
        $this->seed(MedicineDemoSeeder::class);
        $this->assertSame($before, Medicine::query()->orderBy('id')->get()->toArray());
        $this->assertSame(2, AuditEntry::query()->where('event', 'medicine_created')->count());
    }

    // Flujo: excluye datos demo fuera del ambiente local.
    #[DataProvider('nonLocalEnvironments')]
    public function test_seed_does_not_run_outside_local(string $environment): void
    {
        // Preparación: selecciona un entorno sin datos ficticios.
        $this->app->instance('env', $environment);

        // Acción: ejecuta directamente el seeder.
        $this->artisan('db:seed', ['--class' => MedicineDemoSeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertDatabaseCount('medicines', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** @return array<string, array{string}> */
    public static function nonLocalEnvironments(): array
    {
        return ['testing' => ['testing'], 'staging' => ['staging'], 'production' => ['production']];
    }
}
