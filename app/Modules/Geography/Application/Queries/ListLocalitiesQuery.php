<?php

namespace App\Modules\Geography\Application\Queries;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListLocalitiesQuery
{
    /** @param array{search?: string, per_page?: int} $filters */
    public function execute(Department $department, array $filters): LengthAwarePaginator
    {
        return Locality::query()
            ->whereBelongsTo($department)
            ->with('department')
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
