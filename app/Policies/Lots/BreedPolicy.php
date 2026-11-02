<?php

namespace App\Policies\Lots;

use App\Models\Lots\Breed;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class BreedPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_view_breeds');
    }

    public function view(User $user, Breed $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_manage_breeds');
    }

    public function update(User $user, Breed $record): bool
    {
        return $this->create($user);
    }
}
