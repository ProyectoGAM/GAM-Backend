<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Enums\ReportingAndAnalytics\ReportExportStatus;
use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportExportWriter;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSnapshotStore;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\TestCase;

final class ReportSnapshotTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: XLSX y HTML conservan la misma instantánea aunque cambie la producción.
    public function test_export_headers_content_snapshot_and_signed_pagination(): void
    {
        // Preparación: crea más de una página de postura y una exportación autorizada.
        Storage::fake('local');
        $owner = $this->owner();
        $flock = Flock::factory()->create();
        EggCollection::factory()->count(105)->for($flock)->create(['quantity' => 2]);
        $source = app(ReportSourceRegistry::class)->get('lots.egg-production');
        $query = app(ReportQueryNormalizer::class)->normalize($source->definition()->key, ['columns' => ['quantity']]);
        $export = ReportExport::factory()->for($owner)->create(['source_key' => $query->sourceKey, 'definition_version' => $query->definitionVersion, 'query' => $query->toArray()]);
        // Acción: genera un archivo completo con encabezados del cliente.
        $file = app(ReportExportWriter::class)->write($export, $source, $query);
        $export->update([...$file, 'status' => ReportExportStatus::Completed, 'completed_at' => now()]);
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file['path']))->getRows()->all();
        $this->assertCount(105, $rows);
        $this->assertSame(['Huevos'], array_keys($rows[0]));
        $this->assertSame(2, $rows[0]['Huevos']);
        // Mutación: cambia datos después de generar; la instantánea no incorpora esos datos.
        EggCollection::factory()->for($flock)->create(['quantity' => 999]);
        $snapshot = app(ReportSnapshotStore::class)->page($export, 2);
        $this->assertCount(5, $snapshot['result']->rows);
        $this->assertSame(105, $snapshot['result']->total);
        // Acción: comparte y navega a la segunda página mediante firma nueva.
        Sanctum::actingAs($owner);
        $link = $this->postJson('/api/v1/exportaciones-reportes/'.$export->id.'/enlaces-temporales')->assertCreated()->json('url');
        $this->app['auth']->forgetGuards();
        $response = $this->get($link)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('105 filas')->assertDontSee('999');
        preg_match('/href="([^"]+)">Siguiente/', $response->getContent(), $matches);
        $next = html_entity_decode($matches[1], ENT_QUOTES);
        $this->get($next)->assertOk()->assertSee('Página 2 de 2');
        // Acción: alterar el parámetro firmado invalida el enlace.
        $this->get(str_replace('page=2', 'page=3', $next))->assertForbidden();
    }

    // Flujo: el límite nunca produce una planilla recortada como exportación completada.
    public function test_pdf_limit_rejects_excess_and_removes_temporary_files(): void
    {
        // Preparación: configura un límite pequeño y registra un resultado mayor.
        Storage::fake('local');
        config(['reporting.pdf_max_rows' => 1]);
        $flock = Flock::factory()->create();
        EggCollection::factory()->count(2)->for($flock)->create();
        $source = app(ReportSourceRegistry::class)->get('lots.egg-production');
        $query = app(ReportQueryNormalizer::class)->normalize($source->definition()->key, []);
        $export = ReportExport::factory()->create(['format' => 'pdf']);
        // Acción: intenta exportar; el escritor rechaza el exceso y limpia todos los artefactos.
        try {
            app(ReportExportWriter::class)->write($export, $source, $query);
            $this->fail('El límite debía rechazar la exportación.');
        } catch (ReportConflict $exception) {
            $this->assertStringContainsString('límite de 1 filas', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('report-exports'));
    }

    // Flujo: retirar el permiso funcional impide nuevas descargas y comparticiones privadas.
    public function test_source_permission_is_rechecked_on_download_and_share(): void
    {
        // Preparación: conserva permiso general, sin permiso de la fuente.
        $owner = User::factory()->create();
        foreach (['reports.view', 'reports.share'] as $permission) {
            $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $export = ReportExport::factory()->for($owner)->create(['status' => ReportExportStatus::Completed, 'source_key' => 'lots.egg-production']);
        Sanctum::actingAs($owner);
        // Acción: ambos endpoints comprueban el permiso del módulo propietario.
        $this->get('/api/v1/exportaciones-reportes/'.$export->id.'/descarga')->assertForbidden();
        $this->postJson('/api/v1/exportaciones-reportes/'.$export->id.'/enlaces-temporales')->assertForbidden();
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        foreach (['reports.view', 'reports.share', 'egg-collections.view'] as $permission) {
            $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $owner;
    }
}
