<?php

namespace App\Modules\ReportingAndAnalytics\Application\Queries;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListReportPresetsQuery
{
    /** @param array{page?: int, per_page?: int} $filters */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        return ReportPreset::query()
            ->whereBelongsTo($actor)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
