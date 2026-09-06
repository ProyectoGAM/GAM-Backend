<?php

namespace App\Modules\AuditAndTraceability\Infrastructure\Activitylog;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final class SpatieAuditRecorder implements AuditRecorder
{
    public function record(AuditEntryData $entry): void
    {
        $traceId = $entry->traceId ?? Context::get('trace_id');
        $subjectId = $entry->subjectId;
        $properties = $entry->properties;

        // activity_log conserva subject_id como bigint por compatibilidad con el histórico.
        // Los nuevos sujetos UUID se mantienen trazables dentro de properties sin truncarlos.
        if (is_string($subjectId) && ! ctype_digit($subjectId)) {
            $subjectId = null;
            $properties = ['subject_key' => $entry->subjectId, ...$properties];
        }

        AuditEntry::query()->create([
            'log_name' => $entry->logName,
            'description' => $entry->description,
            'subject_type' => $entry->subjectType,
            'subject_id' => $subjectId,
            'event' => $entry->event,
            'causer_type' => $entry->actorType,
            'causer_id' => $entry->actorId,
            'operation_id' => $entry->operationId,
            'trace_id' => is_string($traceId) && $traceId !== '' ? $traceId : Str::uuid()->toString(),
            'source' => $entry->source,
            'up_id' => $entry->upId,
            'attribute_changes' => $entry->attributeChanges,
            'properties' => $properties,
        ]);
    }
}
