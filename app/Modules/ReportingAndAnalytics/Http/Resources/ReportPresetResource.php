<?php

namespace App\Modules\ReportingAndAnalytics\Http\Resources;

use App\Models\ReportingAndAnalytics\ReportPreset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportPreset */
final class ReportPresetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $configuration = $this->configuration;
        unset($configuration['source_key'], $configuration['definition_version']);

        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'source_key' => $this->source_key,
            'definition_version' => $this->definition_version,
            'configuration' => $configuration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
