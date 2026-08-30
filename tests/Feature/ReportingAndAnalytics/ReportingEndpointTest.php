<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Jobs\GenerateReportExport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $response->assertOk()->assertJsonPath('data.0.key', 'inventory.stock-balances');
    }

    // Flujo: valida una consulta inválida sin ejecutar SQL de la fuente.
    public function test_preview_rejects_non_allowlisted_columns(): void
    {
        // Preparación: autentica un usuario con permisos de consulta.
        Sanctum::actingAs($this->userWithPermissions(['reports.view', 'inventory.view']), ['*']);

        // Acción: solicita una columna interna que no está en el contrato.
        $response = $this->postJson('/api/v1/reportes/inventory.stock-balances/previsualizaciones', [
            'columns' => ['stock_balances.password'],
        ]);

        // Verificación: confirma un problema de validación en español.
        $response->assertUnprocessable()->assertJsonPath('type', 'https://httpstatuses.com/422');
    }

    // Flujo: crea un preset y verifica que solo el propietario pueda consultarlo.
    public function test_presets_are_private_to_the_authenticated_user(): void
    {
        // Preparación: autentica al propietario con permisos de presets e inventario.
        $owner = $this->userWithPermissions(['reports.presets.manage', 'reports.view', 'inventory.view']);
        Sanctum::actingAs($owner, ['*']);

        // Acción: crea una configuración válida para la fuente de saldos.
        $created = $this->postJson('/api/v1/configuraciones-reportes', [
            'name' => 'Stock mensual',
            'source_key' => 'inventory.stock-balances',
            'configuration' => ['groupings' => ['product'], 'metrics' => ['physical_stock']],
        ])->assertCreated();

        // Verificación: confirma el preset y su normalización de unidad.
        $created->assertJsonPath('data.configuration.groupings.1', 'base_unit');
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
        $payload = ['format' => 'xlsx', 'groupings' => ['product'], 'metrics' => ['physical_stock']];

        // Acción 1: solicita la generación del archivo.
        $first = $this->postJson('/api/v1/reportes/inventory.stock-balances/exportaciones', $payload, [
            'Idempotency-Key' => $key,
        ])->assertAccepted();

        // Acción 2: repite la solicitud con la misma clave y contenido.
        $second = $this->postJson('/api/v1/reportes/inventory.stock-balances/exportaciones', $payload, [
            'Idempotency-Key' => $key,
        ])->assertAccepted();

        // Verificación: confirma un único registro, un único job y la misma operación.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('report_exports', 1);
        Queue::assertPushed(GenerateReportExport::class, 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'report_export_requested']);
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
