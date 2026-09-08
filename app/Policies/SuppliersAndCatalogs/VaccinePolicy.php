<?php

namespace App\Policies\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;

final readonly class VaccinePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Vaccine $vaccine): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Vaccine $vaccine): bool
    {
        return $this->viewAny($user);
    }

    public function changeStatus(User $user, Vaccine $vaccine): bool
    {
        return $this->viewAny($user);
    }
}
