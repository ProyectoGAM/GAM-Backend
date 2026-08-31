<?php

namespace App\Policies\Lots;

use App\Models\Lots\MortalityCategory;
use App\Models\User;
use App\Modules\Lots\Application\Services\LotsAuthorization;

final readonly class MortalityCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'mortality-categories.view');
    }

    public function view(User $user, MortalityCategory $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'mortality-categories.manage');
    }

    public function update(User $user, MortalityCategory $record): bool
    {
        return $this->create($user);
    }
}
