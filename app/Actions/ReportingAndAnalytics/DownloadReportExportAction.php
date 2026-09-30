<?php

namespace App\Actions\ReportingAndAnalytics;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\ReportingAndAnalytics\ReportExportStatus;
use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final readonly class DownloadReportExportAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private ReportQueryNormalizer $normalizer,
        private ReportSourceRegistry $registry,
    ) {}

    public function execute(ReportExport $export, bool $asHtml = false): Response
    {
        if ($export->status === ReportExportStatus::Expired
            || ($export->status !== ReportExportStatus::Failed
                && $export->expires_at !== null
                && $export->expires_at->isPast())) {
            if ($export->status !== ReportExportStatus::Expired) {
                DB::transaction(function () use ($export): void {
                    $locked = ReportExport::query()->lockForUpdate()->find($export->getKey());
                    if ($locked === null || $locked->status === ReportExportStatus::Expired) {
                        return;
                    }

                    $locked->update(['status' => ReportExportStatus::Expired]);
                    $this->auditRecorder->record(AuditEntryData::forSubject(
                        subject: $locked,
                        actor: $locked->user,
                        logName: 'reporting',
                        event: 'report_export_expired',
                        description: 'Exportación de reporte expirada',
                        properties: [
                            'result' => 'expired',
                            'source_key' => $locked->source_key,
                            'format' => $locked->format->value,
                        ],
                        operationId: $locked->operation_id,
                    ));
                });
            }

            throw new ReportConflict('La exportación ya expiró.');
        }
        if ($export->status !== ReportExportStatus::Completed || $export->path === null) {
            throw new ReportConflict('La exportación todavía no está disponible.');
        }

        $disk = Storage::disk($export->disk);
        if (! $disk->exists($export->path)) {
            throw new ReportConflict('El archivo de la exportación ya no está disponible.');
        }

        if ($asHtml) {
            $source = $this->registry->get($export->source_key);
            $storedQuery = is_array($export->query) ? $export->query : [];
            unset($storedQuery['source_key'], $storedQuery['definition_version']);
            $result = $source->preview($this->normalizer->normalize($export->source_key, $storedQuery));

            return response()->view('reporting.report-export', [
                'export' => $export,
                'source' => $source->definition(),
                'result' => $result,
            ]);
        }

        return $disk->download($export->path, $export->file_name, [
            'Content-Type' => $export->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
