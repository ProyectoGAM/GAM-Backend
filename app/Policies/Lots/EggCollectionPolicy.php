<?php

namespace App\Policies\Lots;

use App\Models\Lots\EggCollection;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class EggCollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_view_egg_collections');
    }

    public function view(User $user, EggCollection $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_manage_egg_collections');
    }

    public function update(User $user, EggCollection $record): bool
    {
        return $this->create($user);
    }
}
