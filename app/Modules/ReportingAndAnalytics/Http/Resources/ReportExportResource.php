<?php

namespace App\Modules\ReportingAndAnalytics\Http\Resources;

use App\Models\ReportingAndAnalytics\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportExport */
final class ReportExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $query = $this->query;
        unset($query['clave_fuente'], $query['version_definicion']);

        return [
            'id' => (int) $this->getKey(),
            'id_operacion' => $this->operation_id,
            'clave_fuente' => $this->source_key,
            'version_definicion' => $this->definition_version,
            'formato' => $this->format->value,
            'estado' => $this->status->value,
            'consulta' => $query,
            'nombre_archivo' => $this->file_name,
            'tipo_mime' => $this->mime_type,
            'tamano_archivo' => $this->file_size,
            'expira_en' => $this->expires_at,
            'completada_en' => $this->completed_at,
            'fallida_en' => $this->failed_at,
            'codigo_falla' => $this->failure_code,
            'mensaje_falla' => $this->failure_message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
