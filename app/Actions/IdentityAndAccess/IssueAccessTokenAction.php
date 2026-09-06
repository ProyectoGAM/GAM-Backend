<?php

namespace App\Actions\IdentityAndAccess;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

final class IssueAccessTokenAction
{
    public function execute(User $user, string $deviceName): NewAccessToken
    {
        return $this->create($user, $deviceName, now()->addDays((int) config('identity.personal_token_days', 90)));
    }

    public function executeShared(User $user, string $deviceName): NewAccessToken
    {
        return $this->create($user, $deviceName, now()->addHours((int) config('identity.shared_session_hours', 8)));
    }

    private function create(User $user, string $deviceName, \DateTimeInterface $expiresAt): NewAccessToken
    {
        return $user->createToken(
            $deviceName,
            config('auth.api_token_abilities', ['api:access']),
            $expiresAt,
        );
    }
}
