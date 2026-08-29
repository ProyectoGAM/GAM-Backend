<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutUserAction
{
    public function execute(User $user): void
    {
        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }
    }
}
