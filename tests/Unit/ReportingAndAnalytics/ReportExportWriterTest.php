<?php

namespace Tests\Unit\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportResultData;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportExportWriter;
use App\Modules\ReportingAndAnalytics\Domain\Contracts\ReportSource;
use App\Modules\ReportingAndAnalytics\Domain\Data\ReportSourceDefinition;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Tests\TestCase;

final class ReportExportWriterTest extends TestCase
{
    // Flujo: genera un XLSX desde filas perezosas y conserva celdas seguras.
    public function test_writes_xlsx_to_the_private_disk(): void
    {
        // Preparación: usa el disco local falso y una exportación sin persistir.
        Storage::fake('local');
        $export = $this->export(ReportExportFormat::Xlsx);

        // Acción: escribe el archivo con la fuente pública de prueba.
        $file = (new ReportExportWriter)->write($export, $this->source(), $this->buildQuery());

        // Verificación: confirma que el archivo se creó en la ruta privada registrada.
        Storage::disk('local')->assertExists($file['path']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file['mime_type']);
    }

    // Flujo: genera un PDF con datos escapados y formato explícito.
    public function test_writes_pdf_to_the_private_disk(): void
    {
        // Preparación: usa el disco local falso y una exportación sin persistir.
        Storage::fake('local');
        $export = $this->export(ReportExportFormat::Pdf);

        // Acción: escribe el PDF con la fuente pública de prueba.
        $file = (new ReportExportWriter)->write($export, $this->source(), $this->buildQuery());

        // Verificación: confirma que el archivo PDF quedó disponible en el disco privado.
        Storage::disk('local')->assertExists($file['path']);
        $this->assertSame('application/pdf', $file['mime_type']);
    }

    private function export(ReportExportFormat $format): ReportExport
    {
        $export = new ReportExport([
            'format' => $format,
            'status' => ReportExportStatus::Processing,
            'disk' => 'local',
        ]);
        $export->setAttribute('id', 42);

        return $export;
    }

    private function buildQuery(): ReportQueryData
    {
        return new ReportQueryData(
            sourceKey: 'test.source',
            definitionVersion: '1.0',
            columns: ['nombre', 'valor'],
            filters: [],
            from: null,
            to: null,
            sorts: [],
            groupings: [],
            metrics: [],
            page: 1,
            perPage: 50,
        );
    }

    private function source(): ReportSource
    {
        return new class implements ReportSource
        {
            public function definition(): ReportSourceDefinition
            {
                return new ReportSourceDefinition(
                    key: 'test.source',
                    definitionVersion: '1.0',
                    label: 'Fuente de prueba',
                    description: 'Fuente usada por las pruebas del escritor.',
                    permission: 'reports.view',
                    columns: [
                        'nombre' => ['label' => 'Nombre', 'tipo' => 'string'],
                        'valor' => ['label' => 'Valor', 'tipo' => 'string'],
                    ],
                    filters: [],
                    groupings: [],
                    metrics: [],
                    sorts: [],
                    formats: ['xlsx', 'pdf'],
                    limits: ['max_page_size' => 100, 'max_range_days' => 366, 'max_export_rows' => 100],
                    defaultSort: 'nombre:asc',
                );
            }

            public function preview(ReportQueryData $query): ReportResultData
            {
                throw new \LogicException('No se usa preview en esta prueba.');
            }

            public function rows(ReportQueryData $query): LazyCollection
            {
                return LazyCollection::make([
                    ['nombre' => 'normal', 'valor' => '12'],
                    ['nombre' => 'formula', 'valor' => '=1+1'],
                ]);
            }
        };
    }
}
