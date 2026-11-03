<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\IdentityAndAccess\AuthSession;
use App\Models\IdentityAndAccess\Role;
use App\Models\SharedDevice;
use App\Models\User;
use App\Services\IdentityAndAccess\AuthSessionService;
use App\Services\IdentityAndAccess\PinHasher;
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
        return $this->authenticate($device, $userId, $pin, false);
    }

    public function executeWeb(SharedDevice $device, int $userId, string $pin): AuthSession
    {
        return $this->authenticate($device, $userId, $pin, true)['session'];
    }

    /** @return array{token: ?NewAccessToken, session: AuthSession} */
    private function authenticate(SharedDevice $device, int $userId, string $pin, bool $web): array
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User || ! $this->eligible($user, $device)) {
            throw $this->invalidPin();
        }
        $this->verifyPin($user, $pin);
        $verifiedHash = $user->pin_hash;

        return DB::transaction(function () use ($device, $user, $verifiedHash, $web): array {
            Role::query()->where('name', 'admin')->lockForUpdate()->first();
            ProductionUnit::query()->whereKey($device->production_unit_id)->lockForUpdate()->firstOrFail();
            $lockedDevice = SharedDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->eligible($lockedUser, $lockedDevice) || ! hash_equals((string) $verifiedHash, (string) $lockedUser->pin_hash)) {
                throw $this->invalidPin();
            }
            $lockedDevice->increment('session_generation');
            $lockedDevice->refresh();
            foreach ($lockedDevice->sessions()->whereNull('revoked_at')->get() as $previous) {
                $previous->forceFill(['revoked_at' => now(), 'revoked_reason' => 'replaced'])->save();
            }
            $token = $web ? null : $this->issueToken->executeShared($lockedUser, 'shared-'.$lockedDevice->getKey());
            $session = $web ? $this->sessions->createSharedWeb($lockedUser, $lockedDevice)
                : $this->sessions->createShared($lockedUser, $lockedDevice, $token);
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedUser, actor: $lockedUser, logName: 'identity', event: 'shared_pin_login',
                description: 'Empleado inició sesión en dispositivo compartido',
                properties: ['shared_device_id' => $lockedDevice->getKey(), 'auth_session_id' => $session->getKey()],
                upId: (int) $lockedDevice->production_unit_id, source: $web ? 'web' : 'api',
            ));

            return ['token' => $token, 'session' => $session];
        });
    }

    private function eligible(User $user, SharedDevice $device): bool
    {
        return $user->usesDevicePin() && $user->pin_enabled && $user->hasAccessRole()
            && ! $user->hasRole('admin') && $user->can('can_login_shared')
            && $user->production_unit_id === $device->production_unit_id && $device->isUsable();
    }

    public function verifyPin(User $user, string $pin): void
    {
        $user->refresh();
        if (! $user->pin_enabled || ! is_string($user->pin_hash)) {
            throw $this->invalidPin();
        }

        $this->ensureNotBlocked($user);

        if ($user->pin_daily_failed_attempts >= (int) config('identity.pin.daily_max_attempts', 20)) {
            throw $this->blocked($user->pin_daily_window_started_at?->addDay());
        }

        try {
            $valid = $this->pinHasher->check($pin, $user->pin_hash);
        } catch (\RuntimeException) {
            throw new IdentityException(503, 'IDENTITY_NOT_CONFIGURED', 'La autenticación no está configurada.');
        }

        if (! $valid) {
            throw $this->recordFailure($user);
        }

        User::query()->whereKey($user->getKey())->update([
            'pin_failed_attempts' => 0,
            'pin_failed_window_started_at' => null,
        ]);
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
                ? $windowStarted->copy()->addMinutes((int) config('identity.pin.window_minutes', 15))
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
