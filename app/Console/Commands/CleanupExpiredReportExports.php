<?php

namespace App\Console\Commands;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\ReportingAndAnalytics\ReportExportStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\ReportingAndAnalytics\ReportExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('app:cleanup-expired-report-exports')]
#[Description('Elimina archivos de reportes expirados y conserva su metadata')]
final class CleanupExpiredReportExports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AuditRecorder $auditRecorder): int
    {
        $expired = 0;
        ReportExport::query()
            ->whereNotIn('status', [ReportExportStatus::Failed->value])
            ->where(fn ($query) => $query->where('status', '<>', ReportExportStatus::Expired->value)->orWhereNotNull('path'))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->lazyById(100)
            ->each(function (ReportExport $export) use (&$expired, $auditRecorder): void {
                $path = $export->path;
                $disk = $export->disk;
                $wasExpired = DB::transaction(function () use ($export, $auditRecorder): bool {
                    $locked = ReportExport::query()->lockForUpdate()->find($export->getKey());
                    if ($locked === null
                        || $locked->status === ReportExportStatus::Failed
                        || $locked->expires_at === null
                        || $locked->expires_at->isFuture()) {
                        return false;
                    }

                    $alreadyExpired = $locked->status === ReportExportStatus::Expired;
                    $locked->update([
                        'status' => ReportExportStatus::Expired,
                        'path' => null,
                        'file_size' => null,
                    ]);
                    if (! $alreadyExpired) {
                        $auditRecorder->record(AuditEntryData::forSubject(
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
                    }

                    return true;
                });

                if (! $wasExpired) {
                    return;
                }
                if ($path !== null) {
                    Storage::disk($disk)->deleteDirectory(dirname($path));
                }
                $expired++;
            });

        $this->info("Se marcaron {$expired} exportaciones como expiradas.");

        return self::SUCCESS;
    }
}
