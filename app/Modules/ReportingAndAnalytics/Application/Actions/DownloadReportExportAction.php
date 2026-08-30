<?php

namespace App\Modules\ReportingAndAnalytics\Application\Actions;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use App\Modules\ReportingAndAnalytics\Domain\Exceptions\ReportConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class DownloadReportExportAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(ReportExport $export): StreamedResponse
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

        return $disk->download($export->path, $export->file_name, [
            'Content-Type' => $export->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
