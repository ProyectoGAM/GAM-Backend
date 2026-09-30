<?php

namespace App\Policies\Lots;

use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;

final readonly class WeighingReferenceSettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return LotsAuthorization::allows($user, 'weighing-settings.manage');
    }

    public function update(User $user, WeighingReferenceSettings $settings): bool
    {
        return $this->viewAny($user);
    }
}
