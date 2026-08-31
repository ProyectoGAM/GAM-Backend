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
        $configuracion = $this->configuration;
        unset($configuracion['clave_fuente'], $configuracion['version_definicion']);

        return [
            'id' => (int) $this->getKey(),
            'nombre' => $this->name,
            'clave_fuente' => $this->source_key,
            'version_definicion' => $this->definition_version,
            'configuracion' => $configuracion,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
