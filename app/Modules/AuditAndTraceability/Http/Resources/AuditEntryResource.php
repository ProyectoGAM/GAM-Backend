<?php

namespace App\Modules\AuditAndTraceability\Http\Resources;

use App\Models\AuditAndTraceability\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEntry */
final class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->getKey(),
            'log_name' => $entry->log_name,
            'event' => $entry->event,
            'description' => $entry->description,
            'actor' => [
                'type' => $entry->causer_type,
                'id' => $entry->causer_id,
            ],
            'subject' => [
                'type' => $entry->subject_type,
                'id' => $entry->subject_id,
            ],
            'operation_id' => $entry->operation_id,
            'trace_id' => $entry->trace_id,
            'source' => $entry->source,
            'up_id' => $entry->up_id,
            'properties' => $entry->properties?->toArray() ?? [],
            'attribute_changes' => $entry->attribute_changes?->toArray() ?? [],
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
