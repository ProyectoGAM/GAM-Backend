<?php

namespace App\Http\Resources\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEntry */
final class AuditActorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $actor = $this->resource->getRelation('auditActorPresentation');

        return [
            'value' => $actor['id'], 'label' => $actor['name'] ?? $actor['current_name'] ?? 'Responsable no disponible',
            'type' => $actor['type'], 'kind' => $actor['kind'], 'status' => $actor['status'],
            'can_manage' => $actor['can_manage'], 'exists' => $actor['exists'],
            'name' => $actor['name'], 'current_name' => $actor['current_name'],
        ];
    }
}
