<?php

namespace App\Services\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\Enums\ReportingAndAnalytics\ReportExportFormat;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\ReportingAndAnalytics\ReportExport;
use Illuminate\Support\Facades\Storage;
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
        $format = $export->getAttribute('format');
        if (! $format instanceof ReportExportFormat) {
            throw new \RuntimeException('El formato de la exportación no es válido.');
        }
        $local = Storage::disk('local');
        $directory = 'report-exports/'.now()->format('Y/m').'/'.$export->getKey();
        $path = $directory.'/reporte-'.$export->getKey().'.'.$format->value;
        $local->makeDirectory($directory);
        try {
            $limit = $source->definition()->limits['max_export_rows'];
            if ($format->value === 'pdf') {
                $limit = min($limit, (int) config('reporting.pdf_max_rows', 2000));
            }
            $header = $this->snapshots->create($directory, $source, $query, $limit);
            $rows = $this->snapshots->rows('local', $directory, $header['total']);
            $labels = array_map(fn (string $column): string => $header['metadata'][$column]['label'], $header['columns']);
            if ($format->value === 'xlsx') {
                $writer = SimpleExcelWriter::create($local->path($path))->addHeader($labels);
                try {
                    foreach ($rows as $row) {
                        $writer->addRow(array_map(fn (string $column): mixed => $this->excelCell($row[$column] ?? null, $header['metadata'][$column]['type']), $header['columns']));
                    }
                } finally {
                    $writer->close();
                }
                $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                $html = view('reporting.export-pdf', ['header' => $header, 'rows' => $rows])->render();
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

        return ['path' => $path, 'file_name' => Str::slug($source->definition()->label).'-'.$export->getKey().'.'.$format->value,
            'mime_type' => $mimeType, 'file_size' => $fileSize];
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
