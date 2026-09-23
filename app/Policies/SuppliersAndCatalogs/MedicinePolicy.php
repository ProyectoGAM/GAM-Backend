<?php

namespace App\Policies\SuppliersAndCatalogs;

use App\Models\User;

final readonly class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->can('management-plans.manage'));
    }

    public function create(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin');
    }
}
