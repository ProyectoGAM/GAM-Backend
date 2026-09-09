<?php

namespace App\Policies\Lots;

use App\Models\Lots\Weighing;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class WeighingPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'weighings.view');
    }

    public function view(User $user, Weighing $weighing): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'weighings.manage');
    }

    public function update(User $user, Weighing $weighing): bool
    {
        return $this->create($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Weighing $weighing): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Weighing $weighing): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Weighing $weighing): bool
    {
        return false;
    }
}
