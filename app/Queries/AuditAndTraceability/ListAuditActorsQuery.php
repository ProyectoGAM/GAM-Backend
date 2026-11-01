<?php

namespace App\Queries\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\User;
use App\Services\AuditAndTraceability\AuditActorPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class ListAuditActorsQuery
{
    public function __construct(private AuditActorPresenter $actors) {}

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, AuditEntry> */
    public function execute(array $filters): LengthAwarePaginator
    {
        $groups = AuditEntry::query()->selectRaw('MAX(id)')->groupBy('causer_type', 'causer_id')
            ->groupByRaw('CASE WHEN causer_id IS NULL AND '.AuditActorPresenter::SYSTEM_EVIDENCE.' THEN 1 ELSE 0 END');
        $query = AuditEntry::query()->whereIn('id', $groups);
        if (isset($filters['buscar'])) {
            $term = $filters['buscar'];
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(function (Builder $query) use ($search, $term): void {
                $query->where('properties->actor_snapshot->name', 'ilike', $search)
                    ->orWhere(function (Builder $query) use ($search): void {
                        $query->whereIn('causer_type', [User::class, (new User)->getMorphClass()])
                            ->whereIn('causer_id', User::withTrashed()->select('id')->where('name', 'ilike', $search));
                    });
                if (mb_stripos('Sistema', $term) !== false) {
                    $query->orWhere(function (Builder $query): void {
                        $query->whereNull('causer_id')->whereRaw(AuditActorPresenter::SYSTEM_EVIDENCE);
                    });
                }
            });
        }
        $entries = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['por_pagina'] ?? 25))->withQueryString();
        $this->actors->prepare($entries->getCollection());

        return $entries;
    }
}
