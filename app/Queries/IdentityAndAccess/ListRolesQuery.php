<?php

namespace App\Queries\IdentityAndAccess;

use App\Models\IdentityAndAccess\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListRolesQuery
{
    /** @param array<string, mixed> $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Role::query()->where('guard_name', 'web')->with('permissions')
            ->withCount(['users' => fn ($query) => $query->withTrashed()])
            ->when($filters['buscar'] ?? null, fn ($query, $search) => $query->where('label', 'ilike', '%'.$search.'%'))
            ->orderBy('label')->orderBy('id')->paginate($filters['por_pagina'] ?? 25);
    }
}
