<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\MortalityRecord;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\ManagementPlans\ManagementExecutionCorrection;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\RationChange;
use App\Models\ManagementPlans\VaccinationApplication;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\ReportingAndAnalytics\ReportExportWriter;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Lots\LotsTestCase;

final class FlockSummaryReportTest extends LotsTestCase
{
    private const string PATH = '/api/v1/reportes/lots.summary/previsualizaciones';

    // Flujo: el período incluye lotes anteriores y suma sólo bajas y postura vigentes de esos días.
    public function test_period_summary_preserves_current_context_and_counts_each_fact_once(): void
    {
        // Preparación: dos lotes comparten galpón, uno carece de actividad, y otro ya terminó antes del período.
        $this->signIn(['can_view_reports', 'can_view_flocks', 'can_view_mortality', 'can_view_egg_collections']);
        $house = PoultryHouse::factory()->create(['bird_capacity' => 500]);
        $flock = Flock::factory()->quarantined()->create(['poultry_house_id' => $house->id, 'production_unit_id' => $house->production_unit_id,
            'entry_date' => '2026-10-01', 'current_quantity' => 80]);
        Flock::factory()->finished()->create(['poultry_house_id' => $house->id, 'production_unit_id' => $house->production_unit_id,
            'entry_date' => '2026-10-02', 'finalized_at' => '2026-11-01T19:00:00-03:00']);
        Flock::factory()->finished()->create(['entry_date' => '2026-09-01', 'finalized_at' => '2026-10-31T23:59:59-03:00']);
        Flock::factory()->create(['entry_date' => '2026-11-03']);
        Flock::factory()->create(['entry_date' => '2026-10-01', 'established_at' => '2026-11-02T00:00:00-03:00']);
        foreach ([['2026-11-01T00:00:00-03:00', 3, 'recorded'], ['2026-11-01T23:59:59-03:00', 2, 'recorded'],
            ['2026-10-31T23:59:59-03:00', 90, 'recorded'], ['2026-11-02T00:00:00-03:00', 80, 'recorded'],
            ['2026-11-01T12:00:00-03:00', 70, 'cancelled']] as [$time, $quantity, $status]) {
            MortalityRecord::factory()->for($flock)->create(['quantity' => $quantity, 'occurred_at' => $time, 'status' => $status]);
        }
        foreach ([['2026-11-01T04:00:00Z', 100, 'recorded'], ['2026-11-01T23:00:00-03:00', 200, 'recorded'],
            ['2026-11-02T04:00:00Z', 999, 'recorded'], ['2026-11-01T12:00:00-03:00', 888, 'cancelled']] as [$time, $quantity, $status]) {
            EggCollection::factory()->for($flock)->create(['quantity' => $quantity, 'occurred_at' => $time, 'status' => $status]);
        }

        // Consulta: devuelve una fila por lote con alojamiento, estado y aves actuales.
        $period = ['from' => '2026-11-01', 'to' => '2026-11-01'];
        $this->postJson(self::PATH, [...$period, 'filters' => [['field' => 'flock_id', 'operator' => 'eq', 'value' => $flock->public_id]]])
            ->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.entry_date', '2026-10-01')
            ->assertJsonPath('data.rows.0.poultry_house', $house->name)
            ->assertJsonPath('data.rows.0.breed', $flock->breed->name)
            ->assertJsonPath('data.rows.0.capacity', '500')->assertJsonPath('data.rows.0.current_quantity', '80')
            ->assertJsonPath('data.rows.0.mortality', '5')->assertJsonPath('data.rows.0.egg_production', '300')
            ->assertJsonPath('data.rows.0.status', 'En cuarentena');

        // Consulta: no multiplica las sumas al combinar bajas y recolecciones del mismo lote.
        $this->postJson(self::PATH, [...$period, 'groupings' => ['poultry_house'],
            'metrics' => ['flock_count', 'bird_count', 'mortality_count', 'egg_count']])->assertOk()
            ->assertJsonPath('data.rows.0', ['poultry_house' => $house->name, 'flock_count' => 2,
                'bird_count' => '80', 'mortality_count' => '5', 'egg_count' => '300']);

        // Consulta: fecha de ingreso y estado se filtran independientemente del período de producción.
        $this->postJson(self::PATH, [...$period, 'filters' => [
            ['field' => 'entry_date', 'operator' => 'eq', 'value' => '2026-10-01'],
            ['field' => 'status', 'operator' => 'eq', 'value' => 'En cuarentena'],
        ]])->assertOk()->assertJsonCount(1, 'data.rows');
        $this->postJson(self::PATH, ['from' => '2026-11-02', 'to' => '2026-11-01'])->assertUnprocessable();
    }

    // Flujo: los permisos del reporte y de las fuentes se comprueban antes de devolver datos.
    public function test_summary_requires_all_source_permissions_and_restricts_unit_totals(): void
    {
        // Solicitud: la fuente completa requiere sesión y el permiso de mortandad.
        $this->postJson(self::PATH)->assertUnauthorized();
        $actor = $this->signIn(['can_view_reports', 'can_view_flocks', 'can_view_egg_collections']);
        $this->postJson(self::PATH)->assertForbidden();
        $actor->roles()->firstOrFail()->givePermissionTo(Permission::findOrCreate('can_view_mortality', 'web'));

        // Preparación: registra una postura propia y otra histórica de una UP distinta para el mismo lote.
        $own = Flock::factory()->create();
        $foreign = Flock::factory()->create();
        EggCollection::factory()->for($own)->create(['quantity' => 11]);
        EggCollection::factory()->for($own)->create(['production_unit_id' => $foreign->production_unit_id, 'poultry_house_id' => $foreign->poultry_house_id, 'quantity' => 999]);
        EggCollection::factory()->for($foreign)->create(['quantity' => 888]);
        $source = app(ReportSourceRegistry::class)->get('lots.summary');
        $query = app(ReportQueryNormalizer::class)->normalize('lots.summary', ['columns' => ['flock', 'egg_production']]);

        // Consulta: el contexto compartido filtra filas y hechos antes de sumar, incluidas opciones.
        app(ProductionUnitAccess::class)->within($own->production_unit_id, function () use ($source, $query, $own): void {
            $this->assertSame([['flock' => $own->code, 'egg_production' => '11']], $source->preview($query)->rows);
            $options = $source->options('flock_id', '', 1);
            $this->assertCount(1, $options['data']);
            $this->assertSame($own->public_id, $options['data'][0]->value);
        });
    }

    // Flujo: la exportación contiene las columnas completas y congela las cantidades del período.
    public function test_summary_exports_the_same_flock_values_to_xlsx(): void
    {
        // Preparación: configura una fuente exportable y una postura registrada.
        Storage::fake('local');
        $actor = $this->signIn(['can_view_reports', 'can_export_reports', 'can_view_flocks', 'can_view_mortality', 'can_view_egg_collections']);
        $flock = Flock::factory()->create();
        EggCollection::factory()->for($flock)->create(['quantity' => 45]);
        $source = app(ReportSourceRegistry::class)->get('lots.summary');
        $query = app(ReportQueryNormalizer::class)->normalize('lots.summary', ['columns' => ['flock', 'poultry_house', 'entry_date', 'breed', 'capacity', 'mortality', 'egg_production', 'status']]);
        $export = ReportExport::factory()->for($actor)->create(['source_key' => 'lots.summary', 'query' => $query->toArray()]);

        // Exportación: abre el XLSX que usa el mismo escritor de la ruta pública.
        $file = app(ReportExportWriter::class)->write($export, $source, $query);
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file['path']))->getRows()->all();
        $this->assertCount(1, $rows);
        $this->assertSame(['Lote', 'Galpón alojado actualmente', 'Día de ingreso', 'Raza', 'Capacidad física del galpón',
            'Mortandad del período', 'Postura del período', 'Estado actual'], array_keys($rows[0]));
        $this->assertSame(45, $rows[0]['Postura del período']);
        $this->assertSame(0, $rows[0]['Mortandad del período']);
    }

    // Flujo: el tipo de manejo se filtra y agrupa conservando lote, galpón y fecha del hecho.
    public function test_management_type_is_present_filterable_and_groupable(): void
    {
        // Preparación: registra prácticas de tipos diferentes y una aplicación corregida.
        $this->signIn(['can_view_reports', 'can_view_management_plans']);
        $flock = Flock::factory()->create();
        foreach (['beak_trimming', 'nest_placement', 'other'] as $type) {
            ManualPractice::factory()->for($flock)->create(['poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id, 'practice_type' => $type, 'occurred_at' => '2026-11-01T12:00:00-03:00']);
        }
        $cancelled = ManualPractice::factory()->for($flock)->create(['practice_type' => 'beak_trimming', 'occurred_at' => '2026-11-01T12:00:00-03:00']);
        ManagementExecutionCorrection::factory()->for($flock)->create(['original_type' => 'manual_practice', 'original_public_id' => $cancelled->public_id]);
        foreach ([VaccinationApplication::class, MedicineApplication::class, RationChange::class, FeedSupply::class] as $model) {
            // Preparación: incluye las otras fuentes de ejecución con su galpón registrado.
            $model::factory()->for($flock)->create(['poultry_house_id' => $flock->poultry_house_id,
                'production_unit_id' => $flock->production_unit_id, 'occurred_at' => '2026-11-01T12:00:00-03:00']);
        }

        // Consulta: obtiene el tipo, lote, galpón y fecha en el detalle exportable.
        $path = '/api/v1/reportes/management.executions/previsualizaciones';
        $period = ['from' => '2026-11-01', 'to' => '2026-11-01'];
        $this->postJson($path, [...$period, 'columns' => ['management_type', 'flock', 'poultry_house_id', 'date', 'practice'],
            'filters' => [['field' => 'management_type', 'operator' => 'eq', 'value' => 'Despique']]])
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.management_type', 'Despique')
            ->assertJsonPath('data.rows.0.flock', $flock->code)
            ->assertJsonPath('data.rows.0.poultry_house_id', $flock->poultry_house_id);

        // Consulta: agrupa sólo las prácticas efectivas del período.
        $this->postJson($path, [...$period, 'groupings' => ['management_type'], 'metrics' => ['execution_count']])
            ->assertOk()->assertJsonCount(7, 'data.rows');
    }
}
