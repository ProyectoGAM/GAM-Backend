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
        unset($query['source_key'], $query['definition_version']);

        return [
            'id' => (int) $this->getKey(),
            'operation_id' => $this->operation_id,
            'source_key' => $this->source_key,
            'definition_version' => $this->definition_version,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'query' => $query,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'expires_at' => $this->expires_at,
            'completed_at' => $this->completed_at,
            'failed_at' => $this->failed_at,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
