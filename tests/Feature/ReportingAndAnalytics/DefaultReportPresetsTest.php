<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Jobs\ReportingAndAnalytics\GenerateReportExport;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingMeasurement;
use App\Models\ManagementPlans\ManagementExecutionCorrection;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\ReportingAndAnalytics\ReportExportWriter;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Database\Seeders\ReportingAndAnalytics\DefaultReportPresetsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Lots\LotsTestCase;

final class DefaultReportPresetsTest extends LotsTestCase
{
    private const array PERMISSIONS = ['can_view_reports', 'can_manage_report_presets', 'can_export_reports', 'can_view_management_plans',
        'can_view_flocks', 'can_view_mortality', 'can_view_egg_collections', 'can_view_weighings', 'can_view_delivery_history', 'can_view_customer_accounts'];

    // Flujo: el seeder crea tres configuraciones sin datos operativos, duplicados ni sobrescritura de personalizaciones.
    public function test_defaults_are_complete_private_and_repeatable_without_overwriting_edits(): void
    {
        // Preparación: utiliza el administrador configurado, sin requerir ningún lote existente.
        $actor = $this->signIn(self::PERMISSIONS);
        config(['auth.admin.email' => $actor->email]);
        $this->seed(DefaultReportPresetsSeeder::class);
        $this->seed(DefaultReportPresetsSeeder::class);

        // Consulta: cada configuración es utilizable y conserva un rango relativo.
        $this->assertDatabaseCount('report_presets', 3);
        $this->assertSame(['Manejos', 'Postura', 'Repartos'], ReportPreset::query()->orderBy('name')->pluck('name')->all());
        foreach (ReportPreset::query()->get() as $preset) {
            $this->assertSame($actor->id, $preset->user_id);
            $this->assertSame('last_month', $preset->configuration['date_preset']);
            $this->assertNull($preset->configuration['from']);
            $configuration = $preset->configuration;
            unset($configuration['source_key'], $configuration['definition_version']);
            $this->postJson('/api/v1/reportes/'.$preset->source_key.'/previsualizaciones', $configuration)->assertOk();
        }
        $delivery = ReportPreset::query()->where('name', 'Repartos')->firstOrFail();
        foreach (['notes', 'loaded_quantity', 'returned_quantity', 'quantity', 'default_merchandise_value', 'entered_unit_prices',
            'merchandise_value', 'paid_at_delivery', 'payment_method', 'outstanding_at_delivery', 'current_debt', 'current_credit', 'current_debt_status'] as $column) {
            $this->assertContains($column, $delivery->configuration['columns']);
        }

        // Mutación: una personalización posterior permanece intacta al repetir la carga.
        $posture = ReportPreset::query()->where('name', 'Postura')->firstOrFail();
        $posture->update(['configuration' => [...$posture->configuration, 'columns' => ['flock']]]);
        $before = $posture->fresh()->getAttributes();
        $this->seed(DefaultReportPresetsSeeder::class);
        $this->assertSame($before, $posture->fresh()->getAttributes());
        $this->assertDatabaseCount('flocks', 0);
        $this->assertDatabaseCount('flock_operations', 0);
    }

    // Flujo: el mes calendario anterior se recalcula al leer y se puede sustituir por fechas explícitas.
    public function test_month_defaults_follow_local_time_and_preserve_custom_date_queries(): void
    {
        // Preparación: desde marzo se debe consultar todo febrero, sin incluir días de marzo.
        $this->travelTo(Carbon::parse('2026-03-31T23:30:00-03:00'));
        $actor = $this->signIn(self::PERMISSIONS);
        config(['auth.admin.email' => $actor->email]);
        $this->seed(DefaultReportPresetsSeeder::class);
        $preset = ReportPreset::query()->where('name', 'Postura')->firstOrFail();

        // Consulta: el contrato entrega plantilla relativa y fechas resueltas para inicializar el front.
        $this->getJson('/api/v1/configuraciones-reportes/'.$preset->id)->assertOk()
            ->assertJsonPath('data.configuration.from', null)->assertJsonPath('data.configuration.date_preset', 'last_month')
            ->assertJsonPath('data.resolved_configuration.from', '2026-02-01')->assertJsonPath('data.resolved_configuration.to', '2026-02-28');
        $this->travelTo(Carbon::parse('2026-04-01T00:30:00-03:00'));
        $this->getJson('/api/v1/configuraciones-reportes/'.$preset->id)->assertOk()
            ->assertJsonPath('data.resolved_configuration.from', '2026-03-01')->assertJsonPath('data.resolved_configuration.to', '2026-03-31');
        $query = app(ReportQueryNormalizer::class)->normalize('lots.egg-production', ['date_preset' => 'last_month', 'from' => '2025-01-01', 'to' => '2025-01-02']);
        $this->assertSame('2025-01-01', $query->from);
        $this->assertSame('2025-01-02', $query->to);
        $this->postJson('/api/v1/reportes/lots.egg-production/previsualizaciones', ['date_preset' => 'unbounded'])->assertUnprocessable();

        // Mutación: guardar otra selección conserva el mes calendario relativo.
        $this->patchJson('/api/v1/configuraciones-reportes/'.$preset->id, ['configuration' => ['date_preset' => 'last_month', 'columns' => ['flock']]])
            ->assertOk()->assertJsonPath('data.configuration.from', null)->assertJsonPath('data.resolved_configuration.to', '2026-03-31');
    }

    // Flujo: el mes anterior incluye todos sus días y respeta año bisiesto, cambio de año y zona local.
    #[TestWith(['2026-11-17T12:00:00-03:00', '2026-10-01', '2026-10-31'])]
    #[TestWith(['2024-03-31T12:00:00-03:00', '2024-02-01', '2024-02-29'])]
    #[TestWith(['2027-01-01T00:01:00-03:00', '2026-12-01', '2026-12-31'])]
    #[TestWith(['2027-01-01T02:30:00Z', '2026-11-01', '2026-11-30'])]
    public function test_previous_month_covers_the_full_local_calendar_month(string $now, string $from, string $to): void
    {
        // Preparación y consulta: resuelve el marcador relativo con el reloj local controlado.
        $this->travelTo(Carbon::parse($now));
        $query = app(ReportQueryNormalizer::class)->normalize('lots.egg-production', ['date_preset' => 'last_month']);

        $this->assertSame($from, $query->from);
        $this->assertSame($to, $query->to);
    }

    // Flujo: la cola mantiene las fechas solicitadas aunque el worker se ejecute al día siguiente.
    public function test_exports_freeze_resolved_dates_before_queueing(): void
    {
        // Preparación: una postura del cierre y una del día siguiente diferencian ambos períodos.
        Storage::fake('local');
        Queue::fake([GenerateReportExport::class]);
        $this->travelTo(Carbon::parse('2026-04-30T23:59:00-03:00'));
        $this->signIn(self::PERMISSIONS);
        $flock = Flock::factory()->create();
        EggCollection::factory()->for($flock)->create(['occurred_at' => '2026-03-31T12:00:00-03:00', 'quantity' => 10]);
        EggCollection::factory()->for($flock)->create(['occurred_at' => '2026-04-01T12:00:00-03:00', 'quantity' => 99]);

        // Solicitud: el pedido guarda fechas concretas y se genera luego con esas mismas fechas.
        $id = $this->postJson('/api/v1/reportes/lots.egg-production/exportaciones', ['format' => 'xlsx', 'date_preset' => 'last_month', 'columns' => ['quantity']],
            ['Idempotency-Key' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        Queue::assertPushed(GenerateReportExport::class, 1);
        $this->travelTo(Carbon::parse('2026-05-01T00:01:00-03:00'));
        $this->app->call([new GenerateReportExport($id), 'handle']);
        $export = ReportExport::query()->findOrFail($id);
        $this->assertSame('2026-03-31', $export->query['to']);
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($export->path))->getRows()->all();
        $this->assertSame([['Huevos' => 10]], $rows);
    }

    // Flujo: Manejos mantiene separados datos del lote, plan, ejecución, pesada y fraccionamiento.
    public function test_management_overview_includes_all_sections_without_multiplying_facts(): void
    {
        // Preparación: un lote anterior al rango recibe un plan, una práctica, una pesada y un fraccionamiento.
        $this->travelTo(Carbon::parse('2026-11-17T12:00:00-03:00'));
        $actor = $this->signIn(self::PERMISSIONS);
        $flock = $this->flockWithHistory();
        $selection = $this->createPublishedPlanSelection($actor, [['type' => 'manual_practice', 'title' => 'Revisión libre', 'timing_kind' => 'unscheduled', 'start_week' => null]]);
        $this->attachPublishedPlan($flock, $actor, $selection);
        ManualPractice::factory()->for($flock)->create(['poultry_house_id' => $flock->poultry_house_id, 'production_unit_id' => $flock->production_unit_id,
            'occurred_at' => '2026-11-02T12:00:00-03:00', 'practice_title_snapshot' => 'Manejo realizado']);
        $cancelled = ManualPractice::factory()->for($flock)->create(['occurred_at' => '2026-11-02T12:00:00-03:00']);
        ManagementExecutionCorrection::factory()->for($flock)->create(['original_type' => 'manual_practice', 'original_public_id' => $cancelled->public_id]);
        $weighing = Weighing::factory()->for($flock)->create(['occurred_at' => '2026-11-03T12:00:00-03:00']);
        WeighingMeasurement::factory()->create(['weighing_id' => $weighing->id, 'weight_g' => '1500']);
        $destination = Flock::factory()->create(['poultry_house_id' => PoultryHouse::factory()->create(['production_unit_id' => $flock->production_unit_id])->id,
            'production_unit_id' => $flock->production_unit_id]);
        FlockMovement::factory()->create(['type' => 'partial_new', 'source_flock_id' => $flock->id, 'destination_flock_id' => $destination->id,
            'source_poultry_house_id' => $flock->poultry_house_id, 'destination_poultry_house_id' => $destination->poultry_house_id,
            'quantity' => 12, 'occurred_at' => '2026-11-04T12:00:00-03:00']);

        // Consulta: cinco filas independientes conservan lote, plan y pesos sin combinaciones cartesianas.
        $configuration = ['from' => '2026-11-01', 'to' => '2026-11-17', 'filters' => [['field' => 'flock_id', 'operator' => 'eq', 'value' => $flock->public_id]]];
        $path = '/api/v1/reportes/management.overview/previsualizaciones';
        $response = $this->postJson($path, $configuration)->assertOk()->assertJsonCount(5, 'data.rows');
        $rows = collect($response->json('data.rows'))->keyBy('section');
        $this->assertSame('1', (string) $rows['Lotes']['plan_revision']);
        $this->assertSame('Revisión libre', $rows['Plan de manejo']['practice']);
        $this->assertSame('Manejo realizado', $rows['Manejos realizados']['practice']);
        $this->assertSame(0, bccomp($rows['Pesadas']['average_weight_g'], '1500', 6));
        $this->assertSame('12', $rows['Movimientos y fraccionamientos']['moved_quantity']);
        $this->assertSame($destination->code, $rows['Movimientos y fraccionamientos']['destination_flock']);
        $this->postJson($path, [...$configuration, 'groupings' => ['flock'], 'metrics' => ['record_count', 'flock_count']])
            ->assertOk()->assertJsonPath('data.rows.0.record_count', 5)->assertJsonPath('data.rows.0.flock_count', 1);

        // Exportación: la configuración conjunta funciona con el escritor existente.
        Storage::fake('local');
        $source = app(ReportSourceRegistry::class)->get('management.overview');
        $query = app(ReportQueryNormalizer::class)->normalize('management.overview', [...$configuration, 'columns' => ['section', 'flock']]);
        $export = ReportExport::factory()->for($actor)->create(['source_key' => 'management.overview', 'query' => $query->toArray()]);
        $file = app(ReportExportWriter::class)->write($export, $source, $query);
        $this->assertCount(5, SimpleExcelReader::create(Storage::disk('local')->path($file['path']))->getRows()->all());
    }

    // Flujo: Manejos exige todos los permisos de sus componentes y no publica movimientos entre UP en contexto PIN.
    public function test_management_permissions_and_pin_unit_boundary_cover_every_section(): void
    {
        // Solicitud: sin sesión o permiso de pesadas se rechaza la vista completa.
        $path = '/api/v1/reportes/management.overview/previsualizaciones';
        $this->postJson($path)->assertUnauthorized();
        $actor = $this->signIn(array_values(array_diff(self::PERMISSIONS, ['can_view_weighings'])));
        $this->postJson($path)->assertForbidden();
        $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_view_weighings', 'web'));
        $own = Flock::factory()->create();
        $other = Flock::factory()->create();
        FlockMovement::factory()->create(['type' => 'partial_existing', 'source_flock_id' => $own->id, 'destination_flock_id' => $other->id,
            'source_poultry_house_id' => $own->poultry_house_id, 'destination_poultry_house_id' => $other->poultry_house_id]);

        // Preparación: autentica una sesión PIN real de la UP propia.
        config(['identity.pin.pepper' => 'test-only-temporary-value']);
        $employee = User::factory()->devicePin(ProductionUnit::query()->findOrFail($own->production_unit_id))->create();
        $employee->syncRoles([$actor->roles()->firstOrFail()]);
        $employee->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_login_shared', 'web'));
        $secret = Str::random(64);
        $device = SharedDevice::query()->create(['name' => 'Reportes de manejo', 'production_unit_id' => $employee->production_unit_id,
            'credential_hash' => hash('sha256', $secret), 'credential_expires_at' => now()->addYear(), 'enrolled_by' => $actor->id, 'enrolled_at' => now()]);
        $token = $employee->createToken('shared-reports', ['*'], now()->addHours(8));
        $session = app(AuthSessionService::class)->createShared($employee, $device->refresh(), $token);
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Shared-Device-Token' => $device->id.'|'.$secret, 'X-GAM-Session' => $session->id];
        $this->app['auth']->forgetGuards();

        // Consulta: sólo queda el lote propio; el movimiento que afecta otra UP también queda fuera.
        $this->postJson($path, ['columns' => ['section', 'flock']], $headers)->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.section', 'Lotes')->assertJsonPath('data.rows.0.flock', $own->code);
        $this->getJson('/api/v1/reportes/management.overview/opciones?field=flock_id', $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.value', $own->public_id);
        $this->postJson($path, ['metrics' => ['flock_count']], $headers)->assertOk()->assertJsonPath('data.rows.0.flock_count', 1);
    }
}
