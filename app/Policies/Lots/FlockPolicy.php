<?php

namespace App\Policies\Lots;

use App\Models\Lots\Flock;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class FlockPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_view_flocks');
    }

    public function view(User $user, Flock $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_manage_flocks');
    }

    public function update(User $user, Flock $record): bool
    {
        return $this->create($user);
    }

    public function changeStatus(User $user, Flock $record): bool
    {
        return $this->create($user);
    }

    public function redistribute(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_redistribute_flocks');
    }

    public function finalize(User $user, Flock $record): bool
    {
        return LotsAuthorization::allows($user, 'can_finalize_flocks');
    }
}
