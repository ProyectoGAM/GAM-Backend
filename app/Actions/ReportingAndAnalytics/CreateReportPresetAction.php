<?php

namespace App\Actions\ReportingAndAnalytics;

use App\Exceptions\ReportingAndAnalytics\ReportConflict;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CreateReportPresetAction
{
    public function __construct(
        private ReportSourceRegistry $registry,
        private ReportQueryNormalizer $normalizer,
    ) {}

    /** @param array{name: string, source_key: string, configuration: array<string, mixed>} $attributes */
    public function execute(array $attributes, User $actor): ReportPreset
    {
        $source = $this->registry->get($attributes['source_key']);
        $this->registry->assertCanRead($actor, $source);
        $query = $this->normalizer->normalize($attributes['source_key'], $attributes['configuration']);

        try {
            return DB::transaction(fn (): ReportPreset => ReportPreset::query()->create([
                'user_id' => $actor->getKey(),
                'name' => $attributes['name'],
                'source_key' => $query->sourceKey,
                'definition_version' => $query->definitionVersion,
                'configuration' => $query->toArray(),
            ]));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw new ReportConflict('Ya tienes una configuración con ese nombre.');
        }
    }
}
