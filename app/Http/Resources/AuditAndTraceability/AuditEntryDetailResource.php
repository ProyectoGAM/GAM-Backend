<?php

namespace App\Http\Resources\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEntry */
final class AuditEntryDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(), 'event' => $this->resource->event,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'actor' => $this->resource->getRelation('auditActorPresentation'),
            'presentation' => $this->resource->getRelation('auditPresentation'),
        ];
    }
}
