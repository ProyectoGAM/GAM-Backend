<?php

namespace Tests\Feature\ReportingAndAnalytics;

use App\Jobs\ReportingAndAnalytics\GenerateReportExport;
use App\Models\Lots\EggCollection;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\TestCase;
use ZipArchive;

final class CombinedReportExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string ENDPOINT = '/api/v1/exportaciones-reportes';

    // Flujo: genera una planilla en orden de selección y exige el permiso de todas las fuentes al descargar.
    public function test_combines_sources_in_order_and_rechecks_access(): void
    {
        Storage::fake('local');
        Bus::fake([GenerateReportExport::class]);
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        EggCollection::factory()->create(['quantity' => 27, 'occurred_at' => '2026-10-12 12:00:00']);
        EggCollection::factory()->create(['quantity' => 99, 'occurred_at' => '2026-09-12 12:00:00']);
        $headers = ['Idempotency-Key' => Str::uuid()->toString()];
        $body = ['format' => 'xlsx', 'sections' => $this->sections()];

        $id = $this->postJson(self::ENDPOINT.'/combinadas', $body, $headers)->assertAccepted()
            ->assertJsonPath('data.source_key', 'combined')
            ->assertJsonPath('data.query.sections.0.columns', ['quantity'])
            ->assertJsonPath('data.query.sections.1.from', null)->json('data.id');
        $this->postJson(self::ENDPOINT.'/combinadas', $body, $headers)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->postJson(self::ENDPOINT.'/combinadas', [...$body, 'sections' => array_reverse($body['sections'])], $headers)->assertConflict();

        app()->call([new GenerateReportExport($id), 'handle']);
        $export = ReportExport::query()->findOrFail($id);
        $path = Storage::disk('local')->path($export->path);
        $this->assertSame([['Huevos' => 27]], SimpleExcelReader::create($path)->getRows()->all());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        $this->assertMatchesRegularExpression('/name="1 [^"]+".*name="2 Saldos de inventario"/s', $workbook);
        $this->get(self::ENDPOINT.'/'.$id.'/descarga')->assertOk()->assertDownload($export->file_name);
        $this->postJson(self::ENDPOINT.'/'.$id.'/enlaces-temporales')->assertForbidden();

        $owner->roles()->firstOrFail()->revokePermissionTo('can_view_inventory');
        Sanctum::actingAs($owner->fresh());
        $this->getJson(self::ENDPOINT.'/'.$id)->assertForbidden();
        $this->get(self::ENDPOINT.'/'.$id.'/descarga')->assertForbidden();
    }

    // Flujo: escribe un PDF con ambas secciones, incluso cuando una fuente solo publica el saldo actual.
    public function test_combines_aggregate_and_current_stock_in_pdf(): void
    {
        Storage::fake('local');
        Bus::fake([GenerateReportExport::class]);
        Sanctum::actingAs($this->owner());
        $sections = $this->sections();
        $sections[1]['query']['metrics'] = ['available_stock'];
        $id = $this->postJson(self::ENDPOINT.'/combinadas', ['format' => 'pdf', 'sections' => $sections],
            ['Idempotency-Key' => Str::uuid()->toString()])->assertAccepted()->json('data.id');

        app()->call([new GenerateReportExport($id), 'handle']);
        $export = ReportExport::query()->findOrFail($id);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($export->path));
        $directory = dirname($export->path);
        Storage::disk('local')->assertExists([$directory.'/sections/1/snapshot/header.json', $directory.'/sections/2/snapshot/header.json']);
        $this->get(self::ENDPOINT.'/'.$id.'/descarga')->assertOk()->assertDownload($export->file_name);
    }

    // Flujo: una solicitud inválida o con una fuente sin permiso no crea un archivo.
    public function test_rejects_duplicate_sources_invalid_dates_and_missing_permission(): void
    {
        Bus::fake([GenerateReportExport::class]);
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $sections = $this->sections();
        $headers = ['Idempotency-Key' => Str::uuid()->toString()];
        $this->postJson(self::ENDPOINT.'/combinadas', ['format' => 'xlsx', 'sections' => [$sections[0], $sections[0]]], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('sections.1.source_key');

        $invalid = $sections;
        $invalid[0]['query']['to'] = '2026-09-30';
        $this->postJson(self::ENDPOINT.'/combinadas', ['format' => 'xlsx', 'sections' => $invalid], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('sections.0.query.to');

        $owner->roles()->firstOrFail()->revokePermissionTo('can_view_inventory');
        Sanctum::actingAs($owner->fresh());
        $this->postJson(self::ENDPOINT.'/combinadas', ['format' => 'xlsx', 'sections' => $sections], $headers)->assertForbidden();
        $this->assertDatabaseCount('report_exports', 0);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $role = $user->roles()->firstOrFail();
        foreach (['can_view_reports', 'can_export_reports', 'can_share_reports', 'can_view_egg_collections', 'can_view_inventory'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function sections(): array
    {
        return [
            ['source_key' => 'lots.egg-production', 'query' => ['columns' => ['quantity'], 'from' => '2026-10-01', 'to' => '2026-10-31']],
            ['source_key' => 'inventory.stock-balances', 'query' => ['columns' => ['product']]],
        ];
    }
}
