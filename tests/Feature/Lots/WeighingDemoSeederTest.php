<?php

namespace Tests\Feature\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingMeasurement;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Lots\WeighingDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WeighingDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: carga la demo completa, comprueba pesajes representativos y repite sólo el seeder de pesajes.
    public function test_local_demo_seeding_creates_weighings_and_is_idempotent(): void
    {
        // Preparación: fija un entorno local reproducible y conserva un usuario ajeno a la demo.
        $this->travelTo(now()->setDate(2026, 8, 30)->startOfDay());
        $this->app->instance('env', 'local');
        Storage::fake('local');
        $user = User::factory()->create();

        // Acción: ejecuta los prerrequisitos reales y la carga local que incluye WeighingDemoSeeder.
        $this->seed(DatabaseSeeder::class);

        // Verificación: existe un singleton de configuración y capturas individuales y grupales.
        $this->assertDatabaseCount('weighing_reference_settings', 1);
        $this->assertDatabaseCount('weighings', 3);
        $this->assertSame(2, Weighing::query()->where('mode', 'individual')->count());
        $this->assertSame(1, Weighing::query()->where('mode', 'group')->count());
        $individualFlock = Flock::query()->where('code', 'DEMO-LOT-A')->sole();
        $this->assertSame(2, Weighing::query()->where('flock_id', $individualFlock->id)->count());
        $this->assertSame(9, WeighingMeasurement::query()->count());

        // Verificación: conserva una captura outlier confirmada y su medición fuera de rango.
        $this->assertSame(1, Weighing::query()->where('outside_expected_range', true)->count());
        $this->assertDatabaseHas('weighing_measurements', [
            'weight_g' => '221.0',
            'outside_expected_range' => true,
        ]);

        // Preparación: guarda el estado completo de pesajes, operaciones y auditorías antes del replay.
        $before = $this->demoSnapshot();
        $userBefore = User::query()->findOrFail($user->id)->getAttributes();

        // Acción: vuelve a ejecutar el seeder específico de pesajes en el mismo entorno local.
        $this->seed(WeighingDemoSeeder::class);

        // Verificación: la recarga no duplica hechos ni reescribe la historia existente.
        $this->assertSame($before, $this->demoSnapshot());
        $this->assertSame($userBefore, User::query()->findOrFail($user->id)->getAttributes());
    }

    // Flujo: omite toda la demo de pesajes cuando ya existe una configuración incompatible.
    public function test_existing_reference_settings_skip_all_weighing_demo_data(): void
    {
        // Preparación: crea sólo el administrador requerido y una configuración ajena.
        $this->app->instance('env', 'local');
        $admin = User::factory()->create(['email' => config('auth.admin.email')]);
        $settings = WeighingReferenceSettings::factory()->create([
            'adult_from_week' => 30,
            'chick_min_weight_g' => '5.0',
            'chick_max_weight_g' => '50.0',
            'adult_min_weight_g' => '80.0',
            'adult_max_weight_g' => '800.0',
            'captured_unit' => 'g',
            'version' => 7,
        ]);

        // Acción: ejecuta el seeder específico sin crear sus lotes demo prerrequisito.
        $this->seed(WeighingDemoSeeder::class);

        // Verificación: conserva la configuración y no intenta crear capturas ni operaciones.
        $settings = $settings->fresh();
        $this->assertSame(30, $settings->adult_from_week);
        $this->assertSame('5.0', $settings->chick_min_weight_g);
        $this->assertSame('50.0', $settings->chick_max_weight_g);
        $this->assertSame('80.0', $settings->adult_min_weight_g);
        $this->assertSame('800.0', $settings->adult_max_weight_g);
        $this->assertSame(7, $settings->version);
        $this->assertDatabaseCount('weighings', 0);
        $this->assertDatabaseCount('weighing_measurements', 0);
        $this->assertDatabaseMissing('flock_operations', [
            'created_by' => $admin->getKey(),
            'command' => 'weighing-settings.save',
        ]);
    }

    /** @return array<string, mixed> */
    private function demoSnapshot(): array
    {
        // Consulta: captura únicamente los registros que el seeder de pesajes puede crear.
        return [
            'settings' => WeighingReferenceSettings::query()->orderBy('id')->get()->map(static fn (WeighingReferenceSettings $settings): array => $settings->getAttributes())->all(),
            'weighings' => Weighing::query()->orderBy('id')->get()->map(static fn (Weighing $weighing): array => $weighing->getAttributes())->all(),
            'measurements' => WeighingMeasurement::query()->orderBy('id')->get()->map(static fn (WeighingMeasurement $measurement): array => $measurement->getAttributes())->all(),
            'operations' => FlockOperation::query()->whereIn('command', ['weighing.record', 'weighing-settings.save'])->orderBy('id')->get()->map(static fn (FlockOperation $operation): array => $operation->getAttributes())->all(),
            'audits' => DB::table('activity_log')->whereIn('event', ['weighing_recorded', 'weighing_reference_saved'])->orderBy('id')->get()->map(static fn (object $entry): array => (array) $entry)->all(),
        ];
    }
}
