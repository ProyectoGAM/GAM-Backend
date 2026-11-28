<?php

namespace App\Services\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\Enums\ReportingAndAnalytics\ReportExportFormat;
use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\ReportingAndAnalytics\ReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

final readonly class ReportExportWriter
{
    public function __construct(private ReportSnapshotStore $snapshots) {}

    /** @return array{path: string, file_name: string, mime_type: string, file_size: int} */
    public function write(ReportExport $export, ReportSource $source, ReportQueryData $query): array
    {
        return $this->writeDocument($export, [['source' => $source, 'query' => $query]], $source->definition()->label);
    }

    /**
     * @param  list<array{source: ReportSource, query: ReportQueryData}>  $sections
     * @return array{path: string, file_name: string, mime_type: string, file_size: int}
     */
    public function writeCombined(ReportExport $export, array $sections): array
    {
        if (count($sections) < 2) {
            throw new ReportConflict('Selecciona al menos dos secciones para el reporte combinado.');
        }

        return $this->writeDocument($export, $sections, 'Reporte de varios módulos');
    }

    /**
     * @param  list<array{source: ReportSource, query: ReportQueryData}>  $sections
     * @return array{path: string, file_name: string, mime_type: string, file_size: int}
     */
    private function writeDocument(ReportExport $export, array $sections, string $title): array
    {
        $format = $export->getAttribute('format');
        if (! $format instanceof ReportExportFormat) {
            throw new \RuntimeException('El formato de la exportación no es válido.');
        }
        $local = Storage::disk('local');
        $directory = 'report-exports/'.now()->format('Y/m').'/'.$export->getKey();
        $path = $directory.'/reporte-'.$export->getKey().'.'.$format->value;
        $local->makeDirectory($directory);
        try {
            $combined = count($sections) > 1;
            $snapshots = $combined
                ? DB::transaction(function () use ($sections, $format, $directory): array {
                    if (DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                    }

                    return $this->capture($sections, $format, $directory);
                })
                : $this->capture($sections, $format, $directory);
            if ($format->value === 'xlsx') {
                $this->writeExcel($local->path($path), $snapshots, $combined);
                $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                $html = view('reporting.export-pdf', ['title' => $title, 'sections' => $snapshots, 'combined' => $combined])->render();
                Pdf::html($html)->driver('dompdf')->format('a4')->landscape()->save($local->path($path));
                $mimeType = 'application/pdf';
            }
            $fileSize = $local->size($path);
            if ($export->disk !== 'local') {
                $destination = Storage::disk($export->disk);
                foreach ($local->allFiles($directory) as $file) {
                    $stream = $local->readStream($file);
                    try {
                        if (! $destination->put($file, $stream)) {
                            throw new \RuntimeException('No fue posible guardar la exportación.');
                        }
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }
                $local->deleteDirectory($directory);
            }
        } catch (Throwable $exception) {
            $local->deleteDirectory($directory);
            if ($export->disk !== 'local') {
                Storage::disk($export->disk)->deleteDirectory($directory);
            }
            throw $exception;
        }

        return ['path' => $path, 'file_name' => Str::slug($title).'-'.$export->getKey().'.'.$format->value,
            'mime_type' => $mimeType, 'file_size' => $fileSize];
    }

    /**
     * @param  list<array{source: ReportSource, query: ReportQueryData}>  $sections
     * @return list<array{header: array, rows: LazyCollection, dated: bool}>
     */
    private function capture(array $sections, ReportExportFormat $format, string $directory): array
    {
        $combined = count($sections) > 1;
        $remaining = $format->value === 'pdf'
            ? (int) config('reporting.pdf_max_rows', 2000)
            : ($combined ? 50000 : PHP_INT_MAX);
        $snapshots = [];
        foreach ($sections as $index => ['source' => $source, 'query' => $query]) {
            $definition = $source->definition();
            $sectionDirectory = $combined ? $directory.'/sections/'.($index + 1) : $directory;
            try {
                $header = $this->snapshots->create($sectionDirectory, $source, $query, min($remaining, $definition->limits['max_export_rows']));
            } catch (ReportConflict $error) {
                if (! $combined) {
                    throw $error;
                }
                throw new ReportConflict($definition->label.': '.$error->getMessage());
            }
            $remaining -= $header['total'];
            $snapshots[] = [
                'header' => $header,
                'rows' => $this->snapshots->rows('local', $sectionDirectory, $header['total']),
                'dated' => $definition->supportsDateRange,
            ];
        }

        return $snapshots;
    }

    /** @param list<array{header: array, rows: LazyCollection, dated: bool}> $sections */
    private function writeExcel(string $path, array $sections, bool $combined): void
    {
        $writer = SimpleExcelWriter::create($path);
        try {
            foreach ($sections as $index => ['header' => $header, 'rows' => $rows]) {
                if ($combined) {
                    $label = str_replace(['[', ']', ':', '?', '*', '/', '\\'], ' ', $header['label']);
                    $name = mb_substr(($index + 1).' '.$label, 0, 31);
                    if ($index === 0) {
                        $writer->nameCurrentSheet($name);
                    } else {
                        $writer->addNewSheetAndMakeItCurrent($name);
                    }
                }
                $writer->addHeader(array_map(fn (string $column): string => $header['metadata'][$column]['label'], $header['columns']));
                foreach ($rows as $row) {
                    $writer->addRow(array_map(fn (string $column): mixed => $this->excelCell($row[$column] ?? null, $header['metadata'][$column]['type']), $header['columns']));
                }
            }
        } finally {
            $writer->close();
        }
    }

    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && ! is_numeric($value) && preg_match('/^[\s]*[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /** Excel admite quince cifras significativas; los valores mayores conservan su texto exacto. */
    private function excelCell(mixed $value, string $type): mixed
    {
        if (is_string($value) && in_array($type, ['number', 'integer', 'quantity', 'money', 'count'], true) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            $decimal = str_contains($value, '.');
            $normalized = $decimal ? rtrim(rtrim($value, '0'), '.') : $value;
            $digits = ltrim(str_replace(['-', '.'], '', $normalized), '0');
            if (strlen($digits) <= 15) {
                return $decimal ? (float) $value : (int) $value;
            }
        }

        return $this->safeCell($value);
    }
}
