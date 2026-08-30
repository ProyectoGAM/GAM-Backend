<?php

namespace App\Modules\ReportingAndAnalytics\Application\Actions;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use App\Modules\ReportingAndAnalytics\Domain\Exceptions\ReportConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

final readonly class CreateTemporaryReportLinkAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @return array{url: string, expires_at: string} */
    public function execute(ReportExport $export, int $expiresInMinutes, User $actor): array
    {
        if ($export->status !== ReportExportStatus::Completed || $export->path === null) {
            throw new ReportConflict('La exportación todavía no está disponible para compartir.');
        }
        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            throw new ReportConflict('La exportación ya expiró.');
        }

        $expiresAt = now()->addMinutes($expiresInMinutes);
        if ($export->expires_at !== null && $expiresAt->greaterThan($export->expires_at)) {
            $expiresAt = $export->expires_at->copy();
        }
        $url = URL::temporarySignedRoute(
            'api.v1.report-exports.download',
            $expiresAt,
            ['reportExport' => $export->getKey(), 'share' => 1],
        );

        DB::transaction(function () use ($export, $actor, $expiresAt): void {
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $export,
                actor: $actor,
                logName: 'reporting',
                event: 'report_export_link_created',
                description: 'Enlace temporal de reporte creado',
                properties: [
                    'result' => 'created',
                    'source_key' => $export->source_key,
                    'format' => $export->format->value,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                operationId: $export->operation_id,
            ));
        });

        return ['url' => $url, 'expires_at' => $expiresAt->toIso8601String()];
    }
}
