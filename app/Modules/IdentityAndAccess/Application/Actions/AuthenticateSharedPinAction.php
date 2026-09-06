<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\IdentityAndAccess\AuthSession;
use App\Models\SharedDevice;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\IdentityAndAccess\Application\Services\AuthSessionService;
use App\Modules\IdentityAndAccess\Application\Services\PinHasher;
use App\Modules\IdentityAndAccess\Http\Exceptions\IdentityException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

final class AuthenticateSharedPinAction
{
    public function __construct(
        private PinHasher $pinHasher,
        private IssueAccessTokenAction $issueToken,
        private AuthSessionService $sessions,
        private AuditRecorder $auditRecorder,
    ) {}

    /** @return array{token: NewAccessToken, session: AuthSession} */
    public function execute(SharedDevice $device, int $userId, string $pin): array
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User || ! $user->pin_enabled || $user->hasRole('admin')) {
            throw $this->invalidPin();
        }

        if (! $user->can('identity.shared.login')) {
            throw new IdentityException(403, 'SHARED_LOGIN_FORBIDDEN', 'El usuario no tiene habilitado el acceso compartido.');
        }

        $this->ensureNotBlocked($user);

        if ($user->pin_daily_failed_attempts >= (int) config('identity.pin.daily_max_attempts', 20)) {
            throw $this->blocked($user->pin_daily_window_started_at?->addDay());
        }

        try {
            $valid = is_string($user->pin_hash) && $this->pinHasher->check($pin, $user->pin_hash);
        } catch (\Throwable $exception) {
            if ($exception instanceof \RuntimeException) {
                throw new IdentityException(503, 'IDENTITY_NOT_CONFIGURED', 'La autenticación no está configurada.');
            }

            throw $exception;
        }

        if (! $valid) {
            throw $this->recordFailure($user);
        }

        return DB::transaction(function () use ($device, $user): array {
            $lockedDevice = SharedDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $lockedDevice->increment('session_generation');
            $lockedDevice->refresh();

            AuthSession::query()
                ->where('shared_device_id', $lockedDevice->getKey())
                ->where('kind', 'shared_user')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'replaced']);

            $token = $this->issueToken->executeShared($user, 'shared-'.$lockedDevice->getKey());
            $session = $this->sessions->createShared($user, $lockedDevice, $token);

            User::query()->whereKey($user->getKey())->update([
                'pin_failed_attempts' => 0,
                'pin_failed_window_started_at' => null,
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $user,
                logName: 'identity',
                event: 'shared_pin_login',
                description: 'Empleado inició sesión en dispositivo compartido',
                properties: ['shared_device_id' => $lockedDevice->getKey(), 'auth_session_id' => $session->getKey()],
                source: 'api',
            ));

            return ['token' => $token, 'session' => $session];
        });
    }

    public function executeWeb(SharedDevice $device, int $userId, string $pin): AuthSession
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User || ! $user->pin_enabled || $user->hasRole('admin')) {
            throw $this->invalidPin();
        }

        if (! $user->can('identity.shared.login')) {
            throw new IdentityException(403, 'SHARED_LOGIN_FORBIDDEN', 'El usuario no tiene habilitado el acceso compartido.');
        }

        $this->ensureNotBlocked($user);

        if ($user->pin_daily_failed_attempts >= (int) config('identity.pin.daily_max_attempts', 20)) {
            throw $this->blocked($user->pin_daily_window_started_at?->addDay());
        }

        try {
            $valid = is_string($user->pin_hash) && $this->pinHasher->check($pin, $user->pin_hash);
        } catch (\Throwable $exception) {
            if ($exception instanceof \RuntimeException) {
                throw new IdentityException(503, 'IDENTITY_NOT_CONFIGURED', 'La autenticación no está configurada.');
            }

            throw $exception;
        }

        if (! $valid) {
            throw $this->recordFailure($user);
        }

        return DB::transaction(function () use ($device, $user): AuthSession {
            $lockedDevice = SharedDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $lockedDevice->increment('session_generation');
            $lockedDevice->refresh();

            AuthSession::query()
                ->where('shared_device_id', $lockedDevice->getKey())
                ->where('kind', 'shared_user')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'replaced']);

            $session = $this->sessions->createSharedWeb($user, $lockedDevice);

            User::query()->whereKey($user->getKey())->update([
                'pin_failed_attempts' => 0,
                'pin_failed_window_started_at' => null,
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $user,
                logName: 'identity',
                event: 'shared_pin_login',
                description: 'Empleado inició sesión en dispositivo compartido',
                properties: ['shared_device_id' => $lockedDevice->getKey(), 'auth_session_id' => $session->getKey()],
                source: 'web',
            ));

            return $session;
        });
    }

    private function ensureNotBlocked(User $user): void
    {
        $now = now();

        if ($user->pin_locked_until?->isFuture()) {
            throw $this->blocked($user->pin_locked_until);
        }

        if ($user->pin_daily_window_started_at?->addDay()->isPast()) {
            User::query()->whereKey($user->getKey())->update([
                'pin_daily_failed_attempts' => 0,
                'pin_daily_window_started_at' => $now,
            ]);
            $user->pin_daily_failed_attempts = 0;
        }

        if ($user->pin_failed_window_started_at?->addMinutes((int) config('identity.pin.window_minutes', 15))->isPast()) {
            User::query()->whereKey($user->getKey())->update([
                'pin_failed_attempts' => 0,
                'pin_failed_window_started_at' => $now,
                'pin_locked_until' => null,
            ]);
            $user->pin_failed_attempts = 0;
        }
    }

    private function recordFailure(User $user): IdentityException
    {
        $blockedUntil = null;

        DB::transaction(function () use ($user, &$blockedUntil): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $now = now();
            $windowExpired = $locked->pin_failed_window_started_at === null
                || $locked->pin_failed_window_started_at->addMinutes((int) config('identity.pin.window_minutes', 15))->isPast();
            $dailyExpired = $locked->pin_daily_window_started_at === null
                || $locked->pin_daily_window_started_at->addDay()->isPast();

            $failed = $windowExpired ? 1 : $locked->pin_failed_attempts + 1;
            $daily = $dailyExpired ? 1 : $locked->pin_daily_failed_attempts + 1;
            $windowStarted = $windowExpired ? $now : $locked->pin_failed_window_started_at;
            $dailyStarted = $dailyExpired ? $now : $locked->pin_daily_window_started_at;
            $blockedUntil = $failed >= (int) config('identity.pin.max_attempts', 5)
                ? $windowStarted?->copy()->addMinutes((int) config('identity.pin.window_minutes', 15))
                : null;

            $locked->forceFill([
                'pin_failed_attempts' => $failed,
                'pin_failed_window_started_at' => $windowStarted,
                'pin_locked_until' => $blockedUntil,
                'pin_daily_failed_attempts' => $daily,
                'pin_daily_window_started_at' => $dailyStarted,
            ])->save();
        });

        if ($blockedUntil !== null) {
            return $this->blocked($blockedUntil);
        }

        return $this->invalidPin();
    }

    private function invalidPin(): IdentityException
    {
        return new IdentityException(401, 'INVALID_PIN', 'El PIN no es válido.');
    }

    private function blocked(?\DateTimeInterface $until): IdentityException
    {
        $seconds = $until === null ? 900 : max(1, $until->getTimestamp() - now()->getTimestamp());

        return new IdentityException(
            429,
            'RATE_LIMITED',
            'Demasiados intentos. Inténtalo nuevamente más tarde.',
            ['Retry-After' => (string) $seconds],
        );
    }
}
