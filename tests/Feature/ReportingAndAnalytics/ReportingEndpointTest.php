<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Jobs\GenerateReportExport;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ReportingEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: lista únicamente las fuentes para las que el usuario tiene permiso funcional.
    public function test_sources_require_reports_and_source_permissions(): void
    {
        // Preparación: autentica un usuario con permiso general y de inventario.
        Sanctum::actingAs($this->userWithPermissions(['reports.view', 'inventory.view']), ['*']);

        // Acción: consulta el catálogo público de fuentes.
        $response = $this->getJson('/api/v1/reportes/fuentes');

        // Verificación: confirma que la fuente real de inventario está publicada.
        $response->assertOk()->assertJsonPath('data.0.key', 'inventario.saldos-stock');
    }

    // Flujo: valida una consulta inválida sin ejecutar SQL de la fuente.
    public function test_preview_rejects_non_allowlisted_columns(): void
    {
        // Preparación: autentica un usuario con permisos de consulta.
        Sanctum::actingAs($this->userWithPermissions(['reports.view', 'inventory.view']), ['*']);

        // Acción: solicita una columna interna que no está en el contrato.
        $response = $this->postJson('/api/v1/reportes/inventario.saldos-stock/previsualizaciones', [
            'columnas' => ['saldos_stock.password'],
        ]);

        // Verificación: confirma un problema de validación en español.
        $response->assertUnprocessable()->assertJsonPath('tipo', 'https://httpstatuses.com/422');
    }

    // Flujo: crea un preset y verifica que solo el propietario pueda consultarlo.
    public function test_presets_are_private_to_the_authenticated_user(): void
    {
        // Preparación: autentica al propietario con permisos de presets e inventario.
        $owner = $this->userWithPermissions(['reports.presets.manage', 'reports.view', 'inventory.view']);
        Sanctum::actingAs($owner, ['*']);

        // Acción: crea una configuración válida para la fuente de saldos.
        $created = $this->postJson('/api/v1/configuraciones-reportes', [
            'nombre' => 'Stock mensual',
            'clave_fuente' => 'inventario.saldos-stock',
            'configuracion' => ['agrupaciones' => ['producto'], 'metricas' => ['stock_fisico']],
        ])->assertCreated();

        // Verificación: confirma el preset y su normalización de unidad.
        $created->assertJsonPath('data.configuracion.agrupaciones.1', 'unidad_base');
        $preset = ReportPreset::query()->firstOrFail();

        // Acción: autentica a otro usuario e intenta leer el preset ajeno.
        Sanctum::actingAs($this->userWithPermissions(['reports.presets.manage']), ['*']);
        $this->getJson('/api/v1/configuraciones-reportes/'.$preset->getKey())->assertForbidden();
    }

    // Flujo: solicita una exportación y verifica idempotencia, cola y auditoría inicial.
    public function test_export_request_is_idempotent_and_queued(): void
    {
        // Preparación: autentica al solicitante y evita ejecutar el worker durante la prueba.
        $actor = $this->userWithPermissions(['reports.view', 'reports.export', 'inventory.view']);
        Sanctum::actingAs($actor, ['*']);
        Queue::fake();
        $key = (string) Str::uuid();
        $payload = ['formato' => 'xlsx', 'agrupaciones' => ['producto'], 'metricas' => ['stock_fisico']];

        // Acción 1: solicita la generación del archivo.
        $first = $this->postJson('/api/v1/reportes/inventario.saldos-stock/exportaciones', $payload, [
            'Idempotency-Key' => $key,
        ])->assertAccepted();

        // Acción 2: repite la solicitud con la misma clave y contenido.
        $second = $this->postJson('/api/v1/reportes/inventario.saldos-stock/exportaciones', $payload, [
            'Idempotency-Key' => $key,
        ])->assertAccepted();

        // Verificación: confirma un único registro, un único job y la misma operación.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('report_exports', 1);
        Queue::assertPushed(GenerateReportExport::class, 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'report_export_requested']);
    }

    // Flujo: descarga un archivo completado con un Bearer token real.
    public function test_completed_exports_download_with_bearer_token(): void
    {
        // Preparación: registra un archivo completado en el disco privado.
        Storage::fake('local');
        $owner = $this->userWithPermissions(['reports.view']);
        $export = $this->completedExport($owner);
        $token = $owner->createToken('download-test')->plainTextToken;

        // Acción 1: descarga el archivo usando la autenticación Sanctum.
        $this->withToken($token)->get('/api/v1/exportaciones-reportes/'.$export->getKey().'/descarga')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=test.xlsx');
    }

    // Flujo: descarga un archivo completado mediante una firma temporal sin autenticación.
    public function test_completed_exports_download_with_temporary_signature(): void
    {
        // Preparación: registra un archivo completado en el disco privado.
        Storage::fake('local');
        $export = $this->completedExport(User::factory()->create());

        // Acción: genera y consume el enlace temporal sin una sesión autenticada.
        $signedUrl = URL::temporarySignedRoute(
            'api.v1.report-exports.download',
            now()->addMinutes(5),
            ['reportExport' => $export->getKey(), 'share' => 1],
        );

        // Verificación: confirma que la firma habilita la misma descarga privada.
        $this->get($signedUrl)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=test.xlsx');
    }

    // Flujo: crea un enlace temporal que muestra el reporte como HTML sin autenticación.
    public function test_temporary_report_link_renders_html_report(): void
    {
        // Preparación: registra una exportación disponible y autentica al propietario con permiso para compartir.
        Storage::fake('local');
        $owner = $this->userWithPermissions(['reports.share']);
        $export = $this->completedExport($owner);
        Sanctum::actingAs($owner, ['*']);

        // Acción 1: solicita el enlace temporal mediante el endpoint existente.
        $link = $this->postJson('/api/v1/exportaciones-reportes/'.$export->getKey().'/enlaces-temporales', [
            'expires_in' => 5,
        ])->assertCreated()->json('url');

        // Acción 2: consume el enlace como visitante anónimo.
        $this->app['auth']->forgetGuards();
        $response = $this->get($link);

        // Verificación: confirma que el enlace devuelve una vista HTML, no el archivo descargable.
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Saldos de inventario')
            ->assertSee('test.xlsx');
    }

    private function completedExport(User $owner): ReportExport
    {
        $export = ReportExport::factory()->for($owner)->create([
            'status' => ReportExportStatus::Completed,
            'path' => 'report-exports/test.xlsx',
            'file_name' => 'test.xlsx',
            'file_size' => 7,
            'completed_at' => now(),
        ]);
        Storage::disk('local')->put('report-exports/test.xlsx', 'content');

        return $export;
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
