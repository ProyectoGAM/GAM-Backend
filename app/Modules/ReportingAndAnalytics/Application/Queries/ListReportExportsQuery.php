<?php

namespace App\Modules\ReportingAndAnalytics\Application\Queries;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListReportExportsQuery
{
    /** @param array{status?: string, per_page?: int} $filters */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        return ReportExport::query()
            ->whereBelongsTo($actor)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }
}
