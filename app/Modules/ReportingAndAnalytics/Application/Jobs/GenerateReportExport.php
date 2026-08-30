<?php

namespace App\Modules\ReportingAndAnalytics\Application\Jobs;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportExportWriter;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportQueryNormalizer;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GenerateReportExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 3600;

    public function __construct(public int $reportExportId) {}

    public function uniqueId(): string
    {
        return (string) $this->reportExportId;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['reporting', 'report-export:'.$this->reportExportId];
    }

    public function handle(
        ReportSourceRegistry $registry,
        ReportQueryNormalizer $normalizer,
        ReportExportWriter $writer,
        AuditRecorder $auditRecorder,
    ): void {
        $export = ReportExport::query()->with('user')->find($this->reportExportId);
        if ($export === null) {
            throw (new ModelNotFoundException)->setModel(ReportExport::class, [$this->reportExportId]);
        }
        if ($export->status === ReportExportStatus::Completed) {
            return;
        }

        $source = $registry->get($export->source_key);
        if ($export->user === null) {
            throw new ModelNotFoundException('El solicitante del reporte ya no está disponible.');
        }
        $registry->assertCanExport($export->user, $source);
        if ($export->definition_version !== $source->definition()->definitionVersion) {
            throw new \RuntimeException('La versión de la fuente de reporte ya no es compatible.');
        }

        $processing = DB::transaction(function () use ($export, $auditRecorder): bool {
            $locked = ReportExport::query()->lockForUpdate()->find($export->getKey());
            if ($locked === null || in_array($locked->status, [ReportExportStatus::Completed, ReportExportStatus::Expired], true)) {
                return false;
            }
            if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                $locked->update(['status' => ReportExportStatus::Expired]);
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

                return false;
            }

            $locked->update(['status' => ReportExportStatus::Processing, 'failure_code' => null, 'failure_message' => null]);

            return true;
        });
        if (! $processing) {
            return;
        }

        $storedQuery = $export->query;
        unset($storedQuery['source_key'], $storedQuery['definition_version']);
        $query = $normalizer->normalize($export->source_key, $storedQuery);
        $file = $writer->write($export, $source, $query);

        DB::transaction(function () use ($export, $file, $auditRecorder): void {
            $locked = ReportExport::query()->lockForUpdate()->findOrFail($export->getKey());
            $locked->update([
                ...$file,
                'status' => ReportExportStatus::Completed,
                'completed_at' => now(),
            ]);

            $auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $locked->user,
                logName: 'reporting',
                event: 'report_export_completed',
                description: 'Exportación de reporte completada',
                properties: [
                    'result' => 'completed',
                    'source_key' => $locked->source_key,
                    'format' => $locked->format->value,
                    'file_size' => $locked->file_size,
                ],
                operationId: $locked->operation_id,
            ));
        });
    }

    public function failed(?Throwable $exception): void
    {
        $export = ReportExport::query()->with('user')->find($this->reportExportId);
        if ($export === null || $export->status === ReportExportStatus::Completed) {
            return;
        }

        $safeMessage = 'La exportación no pudo completarse. Intenta nuevamente.';
        DB::transaction(function () use ($export, $safeMessage): void {
            $locked = ReportExport::query()->lockForUpdate()->find($export->getKey());
            if ($locked === null || $locked->status === ReportExportStatus::Completed) {
                return;
            }
            $locked->update([
                'status' => ReportExportStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'generation_failed',
                'failure_message' => $safeMessage,
            ]);

            app(AuditRecorder::class)->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $export->user,
                logName: 'reporting',
                event: 'report_export_failed',
                description: 'Exportación de reporte fallida',
                properties: [
                    'result' => 'failed',
                    'source_key' => $locked->source_key,
                    'format' => $locked->format->value,
                    'failure_code' => 'generation_failed',
                ],
                operationId: $locked->operation_id,
            ));
        });
    }
}
