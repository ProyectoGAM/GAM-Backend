<?php

namespace App\Policies\Lots;

use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class MortalityRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_view_mortality');
    }

    public function view(User $user, MortalityRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_manage_mortality');
    }

    public function update(User $user, MortalityRecord $record): bool
    {
        return $this->create($user);
    }
}
