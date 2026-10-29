<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedConsumptionCorrection;
use App\Models\Inventory\FeedConsumptionMovement;
use App\Models\Inventory\FlockFeedingChange;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Inventory\FlockFeedingDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FlockFeedingDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: la entrada oficial registra automático, manual y rectificación, y el reintento no duplica efectos.
    public function test_official_demo_has_audited_feeding_history_and_is_idempotent(): void
    {
        // Preparación: habilita únicamente datos ficticios y aísla los archivos de reportes.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00', 'America/Montevideo'));
        $this->app->instance('env', 'local');
        Storage::fake('local');

        // Mutación: carga la demo oficial y conserva el estado observable.
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('feed_consumptions', 10);
        $this->assertDatabaseCount('feed_consumption_corrections', 2);
        $this->assertDatabaseHas('feed_consumptions', ['mode' => 'manual', 'quantity_grams' => 6500]);
        $this->assertDatabaseHas('feed_consumption_corrections', ['quantity_grams' => 6000]);
        $before = [
            FeedConsumption::query()->get()->toArray(), FeedConsumptionCorrection::query()->get()->toArray(),
            FeedConsumptionMovement::query()->get()->toArray(), FlockFeedingChange::query()->get()->toArray(),
            FeedBalance::query()->get()->toArray(), DB::table('activity_log')->count(),
        ];

        // Mutación: el seeder completo del bloque conserva saldos y auditoría.
        $this->seed(FlockFeedingDemoSeeder::class);
        $this->assertSame($before, [
            FeedConsumption::query()->get()->toArray(), FeedConsumptionCorrection::query()->get()->toArray(),
            FeedConsumptionMovement::query()->get()->toArray(), FlockFeedingChange::query()->get()->toArray(),
            FeedBalance::query()->get()->toArray(), DB::table('activity_log')->count(),
        ]);
    }

    // Flujo: entornos no habilitados no reciben datos demo.
    public function test_demo_is_skipped_outside_authorized_environments(): void
    {
        // Mutación: ejecuta el seeder en testing sin permiso de datos ficticios.
        $this->seed(FlockFeedingDemoSeeder::class);

        // Consulta: verifica ausencia de operaciones.
        $this->assertDatabaseCount('feed_consumptions', 0);
        $this->assertDatabaseCount('flock_feeding_changes', 0);
    }
}
