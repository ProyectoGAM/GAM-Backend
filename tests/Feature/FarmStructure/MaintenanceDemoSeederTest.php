<?php

namespace Tests\Feature\FarmStructure;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\FarmStructure\Application\Actions\UpdateMaintenanceAction;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FarmStructure\MaintenanceDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MaintenanceDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: la carga local incluye histórico auditado y una recarga conserva correcciones anteriores.
    public function test_local_database_seeding_includes_maintenance_without_rewriting_history(): void
    {
        // Preparación: fija el entorno y aísla los archivos que generan los otros datos demo.
        $this->travelTo(now()->setDate(2026, 8, 30)->startOfDay());
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Acción: ejecuta el flujo de seeding que indica el README.
        $this->seed(DatabaseSeeder::class);

        // Verificación: los tres galpones tienen hechos pasados, costos exactos y auditoría síncrona.
        $this->assertDatabaseCount('maintenances', 4);
        $this->assertSame(3, Maintenance::query()->distinct()->count('poultry_house_id'));
        $this->assertSame(4, AuditEntry::query()->where('event', 'maintenance_created')->where('source', 'seeder')->count());
        $this->assertFalse(Maintenance::query()->where('maintenance_date', '>', '2026-08-30')->exists());
        $maintenance = Maintenance::query()->where('idempotency_key', '00000000-0000-4000-8000-000000000101')->sole();
        $this->assertSame('1850.00', $maintenance->cost->amount());
        $this->assertSame('UYU', $maintenance->cost->currency());

        // Acción: corrige un costo y vuelve a cargar la demo una semana después.
        $actor = User::query()->where('email', config('auth.admin.email'))->sole();
        app(UpdateMaintenanceAction::class)->execute($maintenance, ['cost_amount' => '1900.50'], 1, 'Ajuste del comprobante demo', $actor);
        $before = $maintenance->fresh()->getAttributes();
        $auditBefore = AuditEntry::query()->orderBy('id')->get()->toArray();
        $housesBefore = PoultryHouse::query()->orderBy('id')->get()->toArray();
        $this->travel(7)->days();
        $this->seed(MaintenanceDemoSeeder::class);

        // Verificación: no duplica registros, altera instalaciones ni reescribe la corrección o su auditoría.
        $this->assertDatabaseCount('maintenances', 4);
        $this->assertSame($before, $maintenance->fresh()->getAttributes());
        $this->assertSame($auditBefore, AuditEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame($housesBefore, PoultryHouse::query()->orderBy('id')->get()->toArray());
    }

    // Flujo: la demo no inserta hechos de mantenimiento fuera del ambiente local.
    #[DataProvider('nonLocalEnvironments')]
    public function test_demo_is_skipped_outside_local_environment(string $environment): void
    {
        // Preparación: selecciona un entorno donde la carga demo está prohibida.
        $this->app->instance('env', $environment);

        // Acción: intenta ejecutar el seeder directamente.
        $this->artisan('db:seed', [
            '--class' => MaintenanceDemoSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        // Verificación: no quedan hechos ni entradas de auditoría.
        $this->assertDatabaseCount('maintenances', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** @return array<string, array{string}> */
    public static function nonLocalEnvironments(): array
    {
        return ['pruebas' => ['testing'], 'preproducción' => ['staging'], 'producción' => ['production']];
    }
}
