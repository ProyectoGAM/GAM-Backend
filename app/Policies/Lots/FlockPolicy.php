<?php

namespace App\Policies\Lots;

use App\Models\Lots\Flock;
use App\Models\User;
use App\Modules\Lots\Application\Services\LotsAuthorization;

final readonly class FlockPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'flocks.view');
    }

    public function view(User $user, Flock $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'flocks.manage');
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
        return LotsAuthorization::allows($user, 'flocks.redistribute');
    }

    public function finalize(User $user, Flock $record): bool
    {
        return LotsAuthorization::allows($user, 'flocks.finalize');
    }
}
