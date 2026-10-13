<?php

namespace App\Services\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\ReportingAndAnalytics\ReportExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

final readonly class ReportSnapshotStore
{
    /** Congela filas y etiquetas por páginas sin cargar el reporte completo en memoria. */
    public function create(string $directory, ReportSource $source, ReportQueryData $query, int $limit): array
    {
        $definition = $source->definition();
        $columns = $query->groupings !== [] || $query->metrics !== [] ? [...$query->groupings, ...$query->metrics] : $query->columns;
        $metadata = [];
        $units = [];
        foreach ($columns as $column) {
            $metadata[$column] = $definition->columns[$column] ?? $definition->groupings[$column] ?? $definition->metrics[$column];
            if (isset($metadata[$column]['unit'])) {
                $units[$column] = $metadata[$column]['unit'];
            }
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory.'/snapshot');
        $total = DB::transaction(function () use ($source, $query, $limit, $directory): int {
            if (DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $page = [];
            $pageNumber = 1;
            $count = 0;
            foreach ($source->rows($query) as $row) {
                if (++$count > $limit) {
                    throw new ReportConflict('El reporte supera el límite de '.$limit.' filas. Acota los filtros o agrupa los resultados.');
                }
                $page[] = $row;
                if (count($page) === 100) {
                    $this->put($directory.'/snapshot/page-'.$pageNumber++.'.json', $page);
                    $page = [];
                }
            }
            if ($page !== [] || $count === 0) {
                $this->put($directory.'/snapshot/page-'.$pageNumber.'.json', $page);
            }

            return $count;
        });
        $header = ['source_key' => $definition->key, 'definition_version' => $definition->definitionVersion,
            'label' => $definition->label, 'columns' => $columns, 'metadata' => $metadata, 'units' => $units,
            'query' => $query->toArray(), 'total' => $total, 'generated_at' => now()->toIso8601String()];
        $this->put($directory.'/snapshot/header.json', $header);

        return $header;
    }

    private function put(string $path, array $content): void
    {
        if (! Storage::disk('local')->put($path, json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            throw new \RuntimeException('No fue posible guardar la instantánea del reporte.');
        }
    }

    /** Recorre una instantánea privada sin consultar las tablas de negocio. */
    public function rows(string $disk, string $directory, int $total): LazyCollection
    {
        return LazyCollection::make(function () use ($disk, $directory, $total): \Generator {
            for ($page = 1; $page <= max(1, (int) ceil($total / 100)); $page++) {
                foreach (json_decode(Storage::disk($disk)->get($directory.'/snapshot/page-'.$page.'.json'), true, 512, JSON_THROW_ON_ERROR) as $row) {
                    yield $row;
                }
            }
        });
    }

    /** @return array{header: array<string, mixed>, result: ReportResultData} */
    public function page(ReportExport $export, int $page): array
    {
        $directory = dirname((string) $export->path);
        $disk = Storage::disk($export->disk);
        if (! $disk->exists($directory.'/snapshot/header.json')) {
            throw new ReportConflict('Esta exportación no tiene una vista HTML congelada. Genera nuevamente el reporte.');
        }
        $header = json_decode($disk->get($directory.'/snapshot/header.json'), true, 512, JSON_THROW_ON_ERROR);
        $lastPage = max(1, (int) ceil($header['total'] / 100));
        if ($page > $lastPage) {
            throw new ReportConflict('La página solicitada no existe en este reporte.');
        }
        $rows = json_decode($disk->get($directory.'/snapshot/page-'.$page.'.json'), true, 512, JSON_THROW_ON_ERROR);

        return ['header' => $header, 'result' => new ReportResultData($header['source_key'], $header['definition_version'],
            $header['columns'], $rows, [], $header['units'], $page, 100, $header['total'], $lastPage, Carbon::parse($header['generated_at']))];
    }
}
