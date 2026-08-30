<?php

namespace App\Modules\ReportingAndAnalytics\Application\Actions;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Application\Jobs\GenerateReportExport;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportQueryNormalizer;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use App\Modules\ReportingAndAnalytics\Domain\Exceptions\ReportConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class RequestReportExportAction
{
    public function __construct(
        private ReportSourceRegistry $registry,
        private ReportQueryNormalizer $normalizer,
        private AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{export: ReportExport, replay: bool}
     */
    public function execute(string $sourceKey, array $input, ReportExportFormat $format, string $idempotencyKey, User $actor): array
    {
        $source = $this->registry->get($sourceKey);
        $this->registry->assertCanExport($actor, $source);
        if (! in_array($format->value, $source->definition()->formats, true)) {
            throw new ReportConflict('El formato solicitado no está disponible para esta fuente.');
        }
        $query = $this->normalizer->normalize($sourceKey, $input);
        $payloadHash = $this->hash($query);
        $idempotencyHash = hash('sha256', $actor->getKey().'|'.$idempotencyKey);

        $result = DB::transaction(function () use ($actor, $format, $idempotencyHash, $payloadHash, $query): array {
            $existing = ReportExport::query()
                ->where('user_id', $actor->getKey())
                ->where('idempotency_key_hash', $idempotencyHash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->payload_hash !== $payloadHash || $existing->format !== $format) {
                    throw new ReportConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
                }

                return ['export' => $existing, 'replay' => true];
            }

            try {
                $export = ReportExport::query()->create([
                    'user_id' => $actor->getKey(),
                    'operation_id' => Str::uuid()->toString(),
                    'idempotency_key_hash' => $idempotencyHash,
                    'payload_hash' => $payloadHash,
                    'source_key' => $query->sourceKey,
                    'definition_version' => $query->definitionVersion,
                    'query' => $query->toArray(),
                    'format' => $format,
                    'status' => 'pending',
                    'disk' => config('reporting.storage_disk', 'local'),
                    'file_name' => 'reporte-'.$query->sourceKey.'.'.$format->value,
                    'expires_at' => now()->addMinutes((int) config('reporting.file_ttl_minutes', 1440)),
                ]);
            } catch (QueryException $exception) {
                if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                    throw $exception;
                }

                throw new ReportConflict('La clave Idempotency-Key ya fue utilizada.');
            }

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $export,
                actor: $actor,
                logName: 'reporting',
                event: 'report_export_requested',
                description: 'Exportación de reporte solicitada',
                properties: [
                    'result' => 'pending',
                    'source_key' => $export->source_key,
                    'definition_version' => $export->definition_version,
                    'format' => $export->format->value,
                    'payload_hash' => $payloadHash,
                ],
                operationId: $export->operation_id,
            ));

            GenerateReportExport::dispatch($export->getKey())
                ->onConnection('redis')
                ->onQueue('reporting')
                ->afterCommit();

            return ['export' => $export, 'replay' => false];
        });

        return $result;
    }

    private function hash(ReportQueryData $query): string
    {
        try {
            return hash('sha256', json_encode($query->toArray(), JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new ReportConflict('No fue posible normalizar la consulta del reporte.');
        }
    }
}
