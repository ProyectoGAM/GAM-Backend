<?php

namespace Tests\Feature\Lots;

use App\Models\Inventory\StockBalance;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Modules\Lots\Application\Actions\UpdateFlockAction;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDemoDataSeeder;
use Database\Seeders\Lots\LotsDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LotsDemoSeederTest extends LotsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // Flujo: la carga general local incluye ejemplos coherentes del ciclo de Lotes.
    public function test_local_database_seed_includes_lots_and_is_repeatable(): void
    {
        // Preparación: cambia sólo el ambiente de aplicación, conservando la base aislada.
        $this->app->instance('env', 'local');

        // Mutación: ejecuta la misma entrada que usa migrate --seed en desarrollo.
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('flocks', ['code' => 'DEMO-LOT-A', 'current_quantity' => 70, 'status' => 'active']);
        $this->assertDatabaseHas('flocks', ['code' => 'DEMO-LOT-B', 'initial_quantity' => 40, 'current_quantity' => 48]);
        $this->assertDatabaseHas('flocks', ['code' => 'DEMO-LOT-C', 'current_quantity' => 0, 'status' => 'finished']);
        $this->assertDatabaseHas('flocks', ['code' => 'DEMO-LOT-D', 'current_quantity' => 25, 'status' => 'quarantined']);
        $this->assertDatabaseCount('flocks', 4);
        $this->assertDatabaseCount('flock_operations', 13);
        $product = Product::query()->where('sku', 'HUEVO-LOTES-DEMO')->firstOrFail();
        $before = Flock::query()->orderBy('id')->get()->toArray();
        $auditCount = DB::table('activity_log')->where('log_name', 'lots')->count();

        // Mutación: repetir la carga general no reinicia los lotes ni duplica huevos.
        $this->seed(LocalDemoDataSeeder::class);
        $this->assertSame($before, Flock::query()->orderBy('id')->get()->toArray());
        $this->assertSame($auditCount, DB::table('activity_log')->where('log_name', 'lots')->count());
        $this->assertDatabaseCount('flock_operations', 13);
        $this->assertSame('12.000000', StockBalance::query()->where('product_id', $product->id)->firstOrFail()->on_hand_quantity);
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'lots')->where('source', '<>', 'seeder')->count());
    }

    // Flujo: el seeder del módulo no sobrescribe modificaciones manuales posteriores.
    public function test_reseeding_preserves_user_edits_and_operation_dates(): void
    {
        // Preparación: carga la demo y modifica uno de sus lotes mediante su Action.
        $this->app->instance('env', 'local');
        $this->seed(DatabaseSeeder::class);
        $flock = Flock::query()->where('code', 'DEMO-LOT-A')->firstOrFail();
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $this->app->make(UpdateFlockAction::class)->execute($flock, [
            'version' => $flock->version, 'notes' => 'Conservar observación del operador', 'idempotency_key' => (string) Str::uuid(),
        ], $actor);
        $before = FlockOperation::query()->orderBy('id')->get()->toArray();
        $this->travel(1)->days();

        // Mutación: resembrar al día siguiente sólo reconoce operaciones ya realizadas.
        $this->seed(LotsDemoSeeder::class);
        $this->assertSame('Conservar observación del operador', $flock->fresh()->notes);
        $this->assertSame($before, FlockOperation::query()->orderBy('id')->get()->toArray());
    }

    // Flujo: la carga de demostración nunca se ejecuta fuera del ambiente local.
    public function test_demo_seeder_is_disabled_in_production(): void
    {
        // Preparación: simula producción sin referencias demo en la base de pruebas.
        $this->assertDatabaseCount('flocks', 0);
        $this->app->instance('env', 'production');

        // Mutación: el seeder sale antes de consultar o crear datos locales.
        $this->app->call([new LotsDemoSeeder, 'run']);
        $this->assertDatabaseCount('flocks', 0);
        $this->assertDatabaseCount('flock_operations', 0);
    }
}
