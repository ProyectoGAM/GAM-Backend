<?php

namespace App\Modules\ReportingAndAnalytics\Application\Services;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Domain\Contracts\ReportSource;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

final readonly class ReportExportWriter
{
    /**
     * @return array{path: string, file_name: string, mime_type: string, file_size: int}
     */
    public function write(ReportExport $export, ReportSource $source, ReportQueryData $query): array
    {
        $disk = Storage::disk($export->disk);
        $localDisk = Storage::disk('local');
        $directory = 'report-exports/'.now()->format('Y/m').'/'.$export->getKey();
        $extension = $export->format->value;
        $path = $directory.'/reporte-'.$export->getKey().'.'.$extension;
        $localDisk->makeDirectory($directory);

        if (! $localDisk instanceof FilesystemAdapter) {
            throw new \RuntimeException('El disco configurado no permite generar archivos locales.');
        }

        $fullPath = $localDisk->path($path);
        $columns = $this->resultColumns($query);
        $rows = $source->rows($query)->take($source->definition()->limits['max_export_rows']);

        try {
            if ($export->format->value === 'xlsx') {
                $this->writeXlsx($fullPath, $columns, $rows);
                $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                $this->writePdf($fullPath, $columns, $rows, $source->definition()->label);
                $mimeType = 'application/pdf';
            }
            $fileSize = filesize($fullPath);
            if ($fileSize === false) {
                throw new \RuntimeException('No fue posible determinar el tamaño de la exportación.');
            }

            if ($export->disk !== 'local') {
                $stream = fopen($fullPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('No fue posible leer la exportación generada.');
                }

                try {
                    if (! $disk->put($path, $stream)) {
                        throw new \RuntimeException('No fue posible guardar la exportación.');
                    }
                } finally {
                    fclose($stream);
                    $localDisk->delete($path);
                }
            }
        } catch (Throwable $exception) {
            $localDisk->delete($path);

            throw $exception;
        }

        return [
            'path' => $path,
            'file_name' => Str::slug($source->definition()->label).'-'.$export->getKey().'.'.$extension,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];
    }

    /** @param list<string> $columns */
    private function writeXlsx(string $path, array $columns, iterable $rows): void
    {
        $writer = SimpleExcelWriter::create($path);
        $writer->addHeader($columns);
        foreach ($rows as $row) {
            $writer->addRow(array_map(fn (mixed $value): mixed => $this->safeCell($value), $row));
        }
        $writer->close();
    }

    /** @param list<string> $columns */
    private function writePdf(string $path, array $columns, iterable $rows, string $title): void
    {
        $headers = implode('', array_map(
            fn (string $column): string => '<th>'.htmlspecialchars($column, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</th>',
            $columns,
        ));
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'.implode('', array_map(
                fn (mixed $value): string => '<td>'.htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>',
                $row,
            )).'</tr>';
        }

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'
            .'@page { margin: 18px; } body { font-family: DejaVu Sans, sans-serif; font-size: 9px; } '
            .'h1 { font-size: 16px; } table { border-collapse: collapse; width: 100%; } '
            .'th, td { border: 1px solid #999; padding: 4px; text-align: left; } th { background: #eee; }'
            .'</style></head><body><h1>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</h1><table><thead><tr>'.$headers.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';

        Pdf::html($html)->driver('dompdf')->format('a4')->save($path);
    }

    /** @param list<string> $columns @return list<string> */
    private function resultColumns(ReportQueryData $query): array
    {
        return $query->groupings !== [] || $query->metrics !== []
            ? [...$query->groupings, ...$query->metrics]
            : $query->columns;
    }

    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && ! is_numeric($value) && preg_match('/^[\s]*[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
