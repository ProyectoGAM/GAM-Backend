<?php

namespace App\Policies\Lots;

use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Modules\Lots\Application\Services\LotsAuthorization;

final readonly class MortalityRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'mortality.view');
    }

    public function view(User $user, MortalityRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return LotsAuthorization::allows($user, 'mortality.manage');
    }

    public function update(User $user, MortalityRecord $record): bool
    {
        return $this->create($user);
    }
}
