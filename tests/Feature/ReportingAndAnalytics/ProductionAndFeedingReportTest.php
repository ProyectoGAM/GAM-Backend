<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Actions\Inventory\ChangeFlockFeedingAction;
use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Actions\Inventory\RecordDailyFeedConsumptionAction;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedRecipe;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\ReportingAndAnalytics\ReportExportWriter;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSnapshotStore;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Lots\LotsTestCase;

final class ProductionAndFeedingReportTest extends LotsTestCase
{
    // Flujo: postura filtra el día local y las entidades antes de agrupar hechos válidos.
    public function test_egg_production_filters_and_period_groupings_use_valid_historical_facts(): void
    {
        // Preparación: mezcla extremos del día, fechas externas, anulaciones y otro lote.
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00-03:00'));
        $this->signIn(['can_view_reports', 'can_view_egg_collections']);
        $flock = Flock::factory()->finished()->create();
        $house = PoultryHouse::query()->findOrFail($flock->poultry_house_id);
        foreach ([['2026-11-01T00:00:00-03:00', 10, 'recorded'], ['2026-11-01T23:59:59-03:00', 20, 'recorded'],
            ['2026-10-31T23:59:59-03:00', 80, 'recorded'], ['2026-11-02T00:00:00-03:00', 90, 'recorded'],
            ['2026-11-01T12:00:00-03:00', 99, 'cancelled']] as [$date, $quantity, $status]) {
            EggCollection::factory()->for($flock)->create(['occurred_at' => $date, 'quantity' => $quantity, 'status' => $status]);
        }
        EggCollection::factory()->create(['occurred_at' => '2026-11-01T12:00:00-03:00', 'quantity' => 70]);
        $flock->forceFill(['poultry_house_id' => PoultryHouse::factory()->create()->id])->save();

        // Consulta: conserva el alojamiento del hecho y ambos extremos del día son inclusivos.
        $path = '/api/v1/reportes/lots.egg-production/previsualizaciones';
        $period = ['from' => '2026-11-01', 'to' => '2026-11-01', 'filters' => [
            ['field' => 'flock_id', 'operator' => 'eq', 'value' => $flock->public_id],
            ['field' => 'poultry_house_id', 'operator' => 'eq', 'value' => $house->id],
            ['field' => 'production_unit_id', 'operator' => 'eq', 'value' => $house->production_unit_id],
        ]];
        $this->postJson($path, [...$period, 'columns' => ['flock', 'poultry_house', 'quantity']])->assertOk()
            ->assertJsonCount(2, 'data.rows')->assertJsonPath('data.rows.0.poultry_house', $house->name);
        foreach (['day' => '2026-11-01', 'week' => '2026-10-26', 'month' => '2026-11-01'] as $grouping => $date) {
            $this->postJson($path, [...$period, 'groupings' => [$grouping, 'flock', 'poultry_house'], 'metrics' => ['egg_count']])
                ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.'.$grouping, $date)
                ->assertJsonPath('data.rows.0.egg_count', '30');
        }

        // Consulta: el filtro numérico opera sobre cada registro y las fechas deben estar ordenadas.
        $this->postJson($path, [...$period, 'filters' => [...$period['filters'], ['field' => 'quantity', 'operator' => 'gte', 'value' => 15]]])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.quantity', '20');
        $this->postJson($path, ['from' => '2026-11-02', 'to' => '2026-11-01'])->assertUnprocessable();
    }

    // Flujo: alimentación usa la última rectificación sin sumar originales ni compensaciones.
    public function test_feeding_reports_effective_recipe_and_exact_quantities_including_zero(): void
    {
        // Preparación: tres jornadas con rectificaciones sucesivas y consumo nulo en la segunda.
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00-03:00'));
        $actor = $this->signIn(['can_view_reports', 'can_view_flock_feeding', 'can_manage_flock_feeding', 'can_correct_feed_consumption']);
        $flock = $this->fedFlock();
        $this->travelTo(Carbon::parse('2026-11-02T12:00:00-03:00'));
        app(ChangeFlockFeedingAction::class)->execute($flock, ['cantidad_diaria_g' => '4000', 'idempotency_key' => (string) Str::uuid()], $actor);
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00-03:00'));
        $first = $this->consume($flock, '2026-11-01');
        $second = $this->consume($flock, '2026-11-02');
        $this->consume($flock, '2026-11-03');
        $recipe = FeedRecipe::factory()->create(['name' => 'Receta rectificada', 'status' => 'inactive']);
        $this->correct($first, $actor, ['receta_id' => $recipe->id, 'cantidad_g' => '3000', 'motivo' => 'Primera corrección']);
        $this->correct($first, $actor, ['cantidad_g' => '1251', 'motivo' => 'Cantidad final']);
        $this->correct($second, $actor, ['cantidad_g' => '0', 'motivo' => 'Sin consumo']);

        // Consulta: conserva receta, cantidad y observación de la última rectificación.
        $path = '/api/v1/reportes/management.feeding/previsualizaciones';
        $response = $this->postJson($path, ['from' => '2026-11-01', 'to' => '2026-11-01',
            'filters' => [['field' => 'feed_recipe_id', 'operator' => 'eq', 'value' => $recipe->id]]])->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.feed_recipe', 'Receta rectificada')
            ->assertJsonPath('data.rows.0.notes', 'Cantidad final')->assertJsonPath('data.rows.0.mode', 'Automático');
        $this->assertSame(0, bccomp($response->json('data.rows.0.quantity'), '1.251', 6));

        // Consulta: cuenta cada jornada una vez y el cero nunca recupera la cantidad original.
        $total = $this->postJson($path, ['metrics' => ['feed_quantity', 'consumption_count']])->assertOk()
            ->assertJsonPath('data.rows.0.consumption_count', 3)->assertJsonPath('data.rows.0.base_unit', 'kg');
        $this->assertSame(0, bccomp($total->json('data.rows.0.feed_quantity'), '5.251', 6));
        $this->postJson($path, ['filters' => [['field' => 'mode', 'operator' => 'eq', 'value' => 'Manual']]])
            ->assertOk()->assertJsonCount(2, 'data.rows')->assertJsonPath('data.rows.0.mode', 'Manual');
        foreach (['day', 'week', 'month'] as $grouping) {
            $grouped = $this->postJson($path, ['from' => '2026-11-01', 'to' => '2026-11-01', 'groupings' => [$grouping, 'flock', 'feed_recipe'], 'metrics' => ['feed_quantity']])
                ->assertOk()->assertJsonCount(1, 'data.rows');
            $this->assertSame(0, bccomp($grouped->json('data.rows.0.feed_quantity'), '1.251', 6));
        }
        $this->assertSame(4000, $first->fresh()->quantity_grams);
    }

    // Flujo: una recuperación conserva el galpón al cierre y permite filtrar por entidades, modo y cantidad.
    public function test_feeding_filters_use_the_house_at_day_end_instead_of_the_current_house(): void
    {
        // Preparación: dos traslados delimitan el alojamiento al cierre de la jornada.
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00-03:00'));
        $this->signIn(['can_view_reports', 'can_view_flock_feeding']);
        $flock = $this->fedFlock();
        $closingHouse = PoultryHouse::factory()->create(['production_unit_id' => $flock->production_unit_id]);
        $currentHouse = PoultryHouse::factory()->create(['production_unit_id' => $flock->production_unit_id]);
        foreach ([['2026-11-01T20:00:00-03:00', $closingHouse], ['2026-11-02T00:00:00-03:00', $currentHouse]] as [$date, $house]) {
            FlockMovement::factory()->create(['destination_flock_id' => $flock->id, 'occurred_at' => $date,
                'after' => [$flock->public_id => ['poultry_house_id' => $house->id, 'current_quantity' => 100]]]);
        }
        $flock->forceFill(['poultry_house_id' => $currentHouse->id])->save();
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00-03:00'));
        $consumption = $this->consume($flock, '2026-11-01');

        // Consulta: los filtros se aplican sobre el hecho histórico, sin atribuirlo al galpón actual.
        $path = '/api/v1/reportes/management.feeding/previsualizaciones';
        $filters = [
            ['field' => 'flock_id', 'operator' => 'eq', 'value' => $flock->public_id],
            ['field' => 'production_unit_id', 'operator' => 'eq', 'value' => $flock->production_unit_id],
            ['field' => 'poultry_house_id', 'operator' => 'eq', 'value' => $closingHouse->id],
            ['field' => 'feed_recipe_id', 'operator' => 'eq', 'value' => $consumption->feed_recipe_id],
            ['field' => 'mode', 'operator' => 'eq', 'value' => 'Automático'],
            ['field' => 'quantity', 'operator' => 'gt', 'value' => '3.999'],
        ];
        $this->postJson($path, ['filters' => $filters, 'from' => '2026-11-01', 'to' => '2026-11-01'])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.poultry_house', $closingHouse->name);
        $this->postJson($path, ['filters' => [['field' => 'poultry_house_id', 'operator' => 'eq', 'value' => $currentHouse->id]]])
            ->assertOk()->assertJsonCount(0, 'data.rows');
        $this->postJson($path, ['filters' => [['field' => 'mode', 'operator' => 'eq', 'value' => 'Manual']]])
            ->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/reportes/management.feeding/opciones?field=feed_recipe_id')->assertOk()
            ->assertJsonPath('data.0.value', $consumption->feed_recipe_id);
    }

    // Flujo: cada fuente exige su permiso y restringe filas, opciones y totales a la UP del contexto.
    public function test_sources_require_their_permissions_and_scope_all_results_to_the_unit(): void
    {
        // Solicitud: el permiso de Plan de manejo no habilita los consumos diarios por sí solo.
        $path = '/api/v1/reportes/management.feeding/previsualizaciones';
        $this->postJson($path)->assertUnauthorized();
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00-03:00'));
        $actor = $this->signIn(['can_view_reports', 'can_view_management_plans']);
        $this->postJson($path)->assertForbidden();
        foreach (['can_view_flock_feeding', 'can_view_egg_collections'] as $permission) {
            $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $own = $this->fedFlock();
        $other = $this->fedFlock();
        EggCollection::factory()->for($own)->create(['quantity' => 11]);
        EggCollection::factory()->for($other)->create(['quantity' => 999]);
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00-03:00'));
        $this->consume($own, '2026-11-01');
        $this->consume($other, '2026-11-01');

        // Preparación: inicia una sesión PIN real para comprobar el límite de UP desde HTTP.
        config(['identity.pin.pepper' => 'test-only-temporary-value']);
        $employee = User::factory()->devicePin(ProductionUnit::query()->findOrFail($own->production_unit_id))->create();
        $employee->syncRoles([$actor->roles()->firstOrFail()]);
        $employee->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_login_shared', 'web'));
        $secret = Str::random(64);
        $device = SharedDevice::query()->create(['name' => 'Tablet de reportes', 'production_unit_id' => $employee->production_unit_id,
            'credential_hash' => hash('sha256', $secret), 'credential_expires_at' => now()->addYear(), 'enrolled_by' => $actor->id, 'enrolled_at' => now()]);
        $token = $employee->createToken('shared-reports', ['*'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($employee, $device->refresh(), $token);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Shared-Device-Token' => $device->id.'|'.$secret, 'X-GAM-Session' => $session->id];
        $this->app['auth']->forgetGuards();

        // Consulta: el contexto compartido filtra antes de agrupar o publicar opciones históricas.
        foreach (['management.feeding' => 'feed_quantity', 'lots.egg-production' => 'egg_count'] as $key => $metric) {
            $base = '/api/v1/reportes/'.$key;
            $this->postJson($base.'/previsualizaciones', ['columns' => ['flock']], $headers)->assertOk()
                ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.flock', $own->code);
            $total = $this->postJson($base.'/previsualizaciones', ['metrics' => [$metric]], $headers)->assertOk();
            $this->assertSame(0, bccomp($total->json('data.rows.0.'.$metric), $key === 'management.feeding' ? '4' : '11', 6));
            $this->getJson($base.'/opciones?field=flock_id', $headers)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.value', $own->public_id);
            $this->postJson($base.'/previsualizaciones', ['filters' => [['field' => 'flock_id', 'operator' => 'eq', 'value' => $other->public_id]]], $headers)
                ->assertOk()->assertJsonCount(0, 'data.rows');
        }
    }

    // Flujo: una configuración independiente exporta a planilla y PDF con una instantánea para la web.
    public function test_feeding_configuration_exports_and_freezes_its_filtered_values(): void
    {
        // Preparación: crea un consumo conocido con permisos de configuración y exportación.
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00-03:00'));
        $actor = $this->signIn(['can_view_reports', 'can_view_flock_feeding', 'can_manage_report_presets', 'can_export_reports', 'can_correct_feed_consumption']);
        $flock = $this->fedFlock();
        $this->travelTo(Carbon::parse('2026-11-04T12:00:00-03:00'));
        $consumption = $this->consume($flock, '2026-11-01');
        $configuration = ['from' => '2026-11-01', 'to' => '2026-11-01', 'columns' => ['flock', 'quantity'],
            'filters' => [['field' => 'flock_id', 'operator' => 'eq', 'value' => $flock->public_id]]];

        // Solicitud: guarda filtros y selección en una configuración reutilizable.
        $saved = $this->postJson('/api/v1/configuraciones-reportes', ['name' => 'Alimentación del lote', 'source_key' => 'management.feeding', 'configuration' => $configuration])
            ->assertCreated()->assertJsonPath('data.source_key', 'management.feeding')->json('data.configuration');
        $source = app(ReportSourceRegistry::class)->get('management.feeding');
        $query = app(ReportQueryNormalizer::class)->normalize('management.feeding', $saved);
        foreach (['xlsx', 'pdf'] as $format) {
            // Exportación: genera un archivo real y conserva las filas para la vista compartida.
            $export = ReportExport::factory()->for($actor)->create(['source_key' => 'management.feeding', 'format' => $format, 'query' => $query->toArray()]);
            $file = app(ReportExportWriter::class)->write($export, $source, $query);
            $export->update($file);
            if ($format === 'xlsx') {
                $rows = SimpleExcelReader::create(Storage::disk('local')->path($file['path']))->getRows()->all();
                $this->assertSame([['Lote' => $flock->code, 'Cantidad de alimento' => 4, 'Unidad' => 'kg']], $rows);
            } else {
                $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($file['path']));
            }
        }

        // Mutación y consulta: una rectificación posterior no altera la instantánea exportada.
        $this->correct($consumption, $actor, ['cantidad_g' => '1000', 'motivo' => 'Recuento posterior']);
        $snapshot = app(ReportSnapshotStore::class)->page($export, 1);
        $this->assertSame(0, bccomp($snapshot['result']->rows[0]['quantity'], '4', 6));
    }

    private function fedFlock(): Flock
    {
        $flock = $this->flockWithHistory();
        $recipe = FeedRecipe::factory()->create();
        $actor = User::query()->findOrFail(auth()->id());
        $selection = $this->createPublishedPlanSelection($actor, [[
            'type' => 'ration_change', 'title' => 'Alimento inicial', 'timing_kind' => 'day', 'start_day' => 1, 'start_week' => null,
            'feed_recipe_id' => $recipe->id, 'grams_per_bird' => 40, 'catalog_type' => 'feed_recipe', 'catalog_id' => $recipe->id,
            'catalog_snapshot' => ['id' => $recipe->id, 'name' => $recipe->name],
        ]]);
        $this->attachPublishedPlan($flock, $actor, $selection);

        return $flock;
    }

    private function consume(Flock $flock, string $date): FeedConsumption
    {
        return app(RecordDailyFeedConsumptionAction::class)->execute($flock->id, $date) ?? throw new \LogicException('La jornada de prueba debe generar un consumo.');
    }

    /** @param array<string, mixed> $data */
    private function correct(FeedConsumption $consumption, User $actor, array $data): void
    {
        app(CorrectFeedConsumptionAction::class)->execute($consumption, [...$data, 'idempotency_key' => (string) Str::uuid()], $actor);
    }
}
