<?php

namespace App\Policies\Lots;

use App\Models\Lots\Breed;
use App\Models\User;
use App\Modules\Lots\Application\Services\LotsAuthorization;

final readonly class BreedPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'breeds.view');
    }

    public function view(User $user, Breed $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'breeds.manage');
    }

    public function update(User $user, Breed $record): bool
    {
        return $this->create($user);
    }
}
