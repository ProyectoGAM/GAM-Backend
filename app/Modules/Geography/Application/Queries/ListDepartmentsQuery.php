<?php

namespace App\Modules\Geography\Application\Queries;

use App\Models\Geography\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListDepartmentsQuery
{
    /** @param array{search?: string, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Department::query()
            ->withCount('localities')
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where('name', 'ilike', '%'.$search.'%'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
