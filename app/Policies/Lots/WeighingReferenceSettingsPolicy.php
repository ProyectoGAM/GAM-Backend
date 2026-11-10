<?php

namespace App\Policies\Lots;

use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class WeighingReferenceSettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'can_manage_weighing_settings');
    }

    public function update(User $user, WeighingReferenceSettings $settings): bool
    {
        return $this->viewAny($user);
    }
}
