<?php

namespace App\Modules\AuditAndTraceability\Application\Queries;

use App\Models\AuditAndTraceability\AuditEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ListAuditEntriesQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        return AuditEntry::query()
            ->when($filters['log_name'] ?? null, function (Builder $query, mixed $logName): void {
                $query->where('log_name', $logName);
            })
            ->when($filters['event'] ?? null, function (Builder $query, mixed $event): void {
                $query->where('event', $event);
            })
            ->when($filters['operation_id'] ?? null, function (Builder $query, mixed $operationId): void {
                $query->where('operation_id', $operationId);
            })
            ->when($filters['trace_id'] ?? null, function (Builder $query, mixed $traceId): void {
                $query->where('trace_id', $traceId);
            })
            ->when($filters['source'] ?? null, function (Builder $query, mixed $source): void {
                $query->where('source', $source);
            })
            ->when($filters['actor_type'] ?? null, function (Builder $query, mixed $actorType): void {
                $query->where('causer_type', $actorType);
            })
            ->when($filters['actor_id'] ?? null, function (Builder $query, mixed $actorId): void {
                $query->where('causer_id', $actorId);
            })
            ->when($filters['subject_type'] ?? null, function (Builder $query, mixed $subjectType): void {
                $query->where('subject_type', $subjectType);
            })
            ->when($filters['subject_id'] ?? null, function (Builder $query, mixed $subjectId): void {
                $query->where('subject_id', $subjectId);
            })
            ->when($filters['up_id'] ?? null, function (Builder $query, mixed $upId): void {
                $query->where('up_id', $upId);
            })
            ->when($filters['from'] ?? null, function (Builder $query, mixed $from): void {
                $query->whereDate('created_at', '>=', $from);
            })
            ->when($filters['to'] ?? null, function (Builder $query, mixed $to): void {
                $query->whereDate('created_at', '<=', $to);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));
    }
}
