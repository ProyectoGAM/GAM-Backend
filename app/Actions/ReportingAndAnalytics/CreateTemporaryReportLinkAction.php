<?php

namespace App\Actions\ReportingAndAnalytics;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\ReportingAndAnalytics\ReportExportStatus;
use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final readonly class CreateTemporaryReportLinkAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @return array{url: string, expires_at: string, file_expires_at: string|null} */
    public function execute(ReportExport $export, int $expiresInMinutes, User $actor): array
    {
        return DB::transaction(function () use ($export, $actor, $expiresInMinutes): array {
            $export = ReportExport::query()->lockForUpdate()->findOrFail($export->getKey());
            if ($export->status === ReportExportStatus::Expired
                || ($export->expires_at !== null && $export->expires_at->lessThanOrEqualTo(now()))) {
                throw new ReportConflict('La exportación ya expiró.');
            }
            if ($export->status !== ReportExportStatus::Completed || $export->path === null) {
                throw new ReportConflict('La exportación todavía no está disponible para compartir.');
            }
            if (! Storage::disk($export->disk)->exists($export->path)) {
                throw new ReportConflict('El archivo de la exportación ya no está disponible.');
            }

            $expiresAt = now()->addMinutes($expiresInMinutes);
            $previousExpiry = $export->expires_at?->copy();
            $changes = [];
            if ($previousExpiry !== null && $expiresAt->greaterThan($previousExpiry)) {
                $export->update(['expires_at' => $expiresAt]);
                $changes['expires_at'] = [
                    'old' => $previousExpiry->toIso8601String(),
                    'new' => $expiresAt->toIso8601String(),
                ];
            }

            $url = URL::temporarySignedRoute(
                'api.v1.report-exports.download.es',
                $expiresAt,
                ['reportExport' => $export->getKey(), 'share' => 1, 'view' => 'html'],
            );
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
                    'expires_in_minutes' => $expiresInMinutes,
                ],
                attributeChanges: $changes,
                operationId: $export->operation_id,
            ));

            return [
                'url' => $url,
                'expires_at' => $expiresAt->toIso8601String(),
                'file_expires_at' => $export->expires_at?->toIso8601String(),
            ];
        });
    }
}
