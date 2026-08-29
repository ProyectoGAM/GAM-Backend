<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

final class IssueAccessTokenAction
{
    public function execute(User $user, string $deviceName): NewAccessToken
    {
        return $user->createToken(
            $deviceName,
            config('auth.api_token_abilities', ['api:access']),
            now()->addMinutes((int) config('sanctum.expiration')),
        );
    }
}
