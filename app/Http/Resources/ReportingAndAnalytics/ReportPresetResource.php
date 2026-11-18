<?php

namespace App\Http\Resources\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Services\ReportingAndAnalytics\ReportQueryNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportPreset */
final class ReportPresetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $this->resource->getAttribute('configuration');
        unset($configuration['source_key'], $configuration['definition_version']);
        $resolvedConfiguration = $configuration;
        if (($configuration['date_preset'] ?? null) !== null) {
            $resolvedConfiguration = app(ReportQueryNormalizer::class)->normalize($this->source_key, $configuration)->toArray();
            unset($resolvedConfiguration['source_key'], $resolvedConfiguration['definition_version']);
        }

        return [
            'id' => (int) $this->getKey(),
            'name' => $this->resource->getAttribute('name'),
            'source_key' => $this->source_key,
            'definition_version' => $this->definition_version,
            'configuration' => $configuration,
            'resolved_configuration' => $resolvedConfiguration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
