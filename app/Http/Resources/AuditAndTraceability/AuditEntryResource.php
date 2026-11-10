<?php

namespace App\Http\Resources\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Services\AuditAndTraceability\AuditEventCatalog;
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
            'log_name' => AuditEventCatalog::moduleName($entry->log_name, $entry->event),
            'event' => $entry->event,
            'description' => $entry->description,
            'actor' => $entry->getRelation('auditActorPresentation'),
            'presentation' => $entry->getRelation('auditPresentation'),
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
