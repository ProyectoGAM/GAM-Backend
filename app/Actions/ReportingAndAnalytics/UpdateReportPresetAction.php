<?php

namespace App\Actions\ReportingAndAnalytics;

use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateReportPresetAction
{
    public function __construct(
        private ReportSourceRegistry $registry,
        private ReportQueryNormalizer $normalizer,
    ) {}

    /** @param array{name?: string, source_key?: string, configuration?: array<string, mixed>} $attributes */
    public function execute(ReportPreset $preset, array $attributes, User $actor): ReportPreset
    {
        $sourceKey = $attributes['source_key'] ?? $preset->source_key;
        $configuration = $attributes['configuration'] ?? $preset->configuration;
        unset($configuration['clave_fuente'], $configuration['version_definicion']);
        $source = $this->registry->get($sourceKey);
        $this->registry->assertCanRead($actor, $source);
        $query = $this->normalizer->normalize($sourceKey, $configuration);

        try {
            return DB::transaction(function () use ($preset, $attributes, $query): ReportPreset {
                $preset->update([
                    'name' => $attributes['name'] ?? $preset->name,
                    'source_key' => $query->sourceKey,
                    'definition_version' => $query->definitionVersion,
                    'configuration' => $query->toArray(),
                ]);

                return $preset->refresh();
            });
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw new ReportConflict('Ya tienes otra configuración con ese nombre.');
        }
    }
}
