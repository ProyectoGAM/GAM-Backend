<?php

namespace App\Policies\Lots;

use App\Models\Lots\EggCollection;
use App\Models\User;
use App\Modules\Lots\Application\Services\LotsAuthorization;

final readonly class EggCollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'egg-collections.view');
    }

    public function view(User $user, EggCollection $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'egg-collections.manage');
    }

    public function update(User $user, EggCollection $record): bool
    {
        return $this->create($user);
    }
}
