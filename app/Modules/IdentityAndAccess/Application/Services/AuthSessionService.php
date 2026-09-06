<?php

namespace App\Modules\IdentityAndAccess\Application\Services;

use App\Models\IdentityAndAccess\AuthSession;
use App\Models\SharedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthSessionService
{
    public function createPersonal(User $user, NewAccessToken $token, string $transport = 'bearer'): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'kind' => 'personal',
            'transport' => $transport,
            'auth_method' => 'password',
            'personal_access_token_id' => $token->accessToken->getKey(),
            'issued_at' => now(),
            'expires_at' => $token->accessToken->getAttribute('expires_at'),
            'last_user_activity_at' => now(),
        ]);
    }

    public function createWebPersonal(User $user): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'kind' => 'personal',
            'transport' => 'cookie',
            'auth_method' => 'password',
            'issued_at' => now(),
            'expires_at' => now()->addDays((int) config('identity.personal_session_days', 90)),
            'last_user_activity_at' => now(),
        ]);
    }

    public function createShared(User $user, SharedDevice $device, NewAccessToken $token): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'kind' => 'shared_user',
            'transport' => 'bearer',
            'auth_method' => 'pin',
            'shared_device_id' => $device->getKey(),
            'device_generation' => $device->session_generation,
            'personal_access_token_id' => $token->accessToken->getKey(),
            'issued_at' => now(),
            'expires_at' => now()->addHours((int) config('identity.shared_session_hours', 8)),
            'last_user_activity_at' => now(),
        ]);
    }

    public function createSharedWeb(User $user, SharedDevice $device): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'kind' => 'shared_user',
            'transport' => 'cookie',
            'auth_method' => 'pin',
            'shared_device_id' => $device->getKey(),
            'device_generation' => $device->session_generation,
            'issued_at' => now(),
            'expires_at' => now()->addHours((int) config('identity.shared_session_hours', 8)),
            'last_user_activity_at' => now(),
        ]);
    }

    public function current(Request $request): ?AuthSession
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return AuthSession::query()->where('personal_access_token_id', $token->getKey())->first();
        }

        $sessionId = $request->hasSession() ? $request->session()->get('auth_session_id') : null;

        return is_string($sessionId)
            ? AuthSession::query()->find($sessionId)
            : null;
    }

    public function revoke(?AuthSession $session, string $reason = 'logout'): void
    {
        if ($session === null || $session->revoked_at !== null) {
            return;
        }

        $session->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        if ($session->personal_access_token_id !== null) {
            $session->user?->tokens()->whereKey($session->personal_access_token_id)->delete();
        }
    }

    /** @return Collection<int, AuthSession> */
    public function personalFor(User $user): Collection
    {
        return AuthSession::query()
            ->where('user_id', $user->getKey())
            ->where('kind', 'personal')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('issued_at')
            ->get();
    }

    /**
     * @return array{id: string, kind: string, auth_method: string, expires_at: string|null, idle_timeout_seconds: int|null}
     */
    public function payload(AuthSession $session): array
    {
        return [
            'id' => (string) $session->getKey(),
            'kind' => $session->kind,
            'auth_method' => $session->auth_method,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'idle_timeout_seconds' => $session->kind === 'shared_user'
                ? (int) config('identity.shared_idle_seconds', 120)
                : null,
        ];
    }
}
