<?php

namespace App\Policies\SuppliersAndCatalogs;

use App\Models\User;

final readonly class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->can('can_manage_management_plans'));
    }

    public function create(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin');
    }
}
