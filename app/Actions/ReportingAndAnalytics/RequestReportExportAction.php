<?php

namespace App\Actions\ReportingAndAnalytics;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\ReportingAndAnalytics\ReportExportFormat;
use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Jobs\ReportingAndAnalytics\GenerateReportExport;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
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
        $query = $this->normalizer->normalize($sourceKey, [...$input, 'page' => 1, 'per_page' => min(50, $source->definition()->limits['max_page_size'])]);

        return $this->queue($sourceKey, $query->definitionVersion, $query->toArray(), $format, $idempotencyKey, $actor);
    }

    /**
     * @param  list<array{source_key: string, query: array<string, mixed>}>  $sections
     * @return array{export: ReportExport, replay: bool}
     */
    public function executeCombined(array $sections, ReportExportFormat $format, string $idempotencyKey, User $actor): array
    {
        $queries = [];
        foreach ($sections as $section) {
            $sourceKey = $section['source_key'];
            $source = $this->registry->get($sourceKey);
            $this->registry->assertCanRead($actor, $source);
            $this->registry->assertCanExport($actor, $source);
            if (! in_array($format->value, $source->definition()->formats, true)) {
                throw new ReportConflict('El formato solicitado no está disponible para '.$source->definition()->label.'.');
            }
            $queries[] = $this->normalizer->normalize($sourceKey, [
                ...$section['query'],
                'page' => 1,
                'per_page' => min(50, $source->definition()->limits['max_page_size']),
            ])->toArray();
        }

        return $this->queue(ReportExport::COMBINED_SOURCE, '1', ['sections' => $queries], $format, $idempotencyKey, $actor);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{export: ReportExport, replay: bool}
     */
    private function queue(string $sourceKey, string $version, array $query, ReportExportFormat $format, string $idempotencyKey, User $actor): array
    {
        $payloadHash = $this->hash($query);
        $idempotencyHash = hash('sha256', $actor->getKey().'|'.$idempotencyKey);

        $result = DB::transaction(function () use ($actor, $format, $idempotencyHash, $payloadHash, $sourceKey, $version, $query): array {
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
                    'production_unit_id' => $actor->usesDevicePin() ? $actor->production_unit_id : null,
                    'user_id' => $actor->getKey(),
                    'operation_id' => Str::uuid()->toString(),
                    'idempotency_key_hash' => $idempotencyHash,
                    'payload_hash' => $payloadHash,
                    'source_key' => $sourceKey,
                    'definition_version' => $version,
                    'query' => $query,
                    'format' => $format,
                    'status' => 'pending',
                    'disk' => config('reporting.storage_disk', 'local'),
                    'file_name' => ($sourceKey === ReportExport::COMBINED_SOURCE ? 'reporte-varios-modulos' : 'reporte-'.$sourceKey).'.'.$format->value,
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

            return ['export' => $export, 'replay' => false];
        });

        if (! $result['replay']) {
            GenerateReportExport::dispatch($result['export']->getKey())
                ->onConnection('redis')
                ->onQueue('reporting')->afterCommit();
        }

        return $result;
    }

    /** @param array<string, mixed> $query */
    private function hash(array $query): string
    {
        try {
            return hash('sha256', json_encode($query, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new ReportConflict('No fue posible normalizar la consulta del reporte.');
        }
    }
}
