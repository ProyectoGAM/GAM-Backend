<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Services\IdentityAndAccess\PinHasher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SetUserPinAction
{
    public function __construct(
        private PinHasher $pinHasher,
        private AuditRecorder $auditRecorder,
    ) {}

    public function execute(User $actor, User $user, string $pin): void
    {
        if ($user->hasRole('admin')) {
            throw new IdentityException(422, 'ADMIN_PIN_NOT_ALLOWED', 'Las cuentas administradoras no pueden usar PIN compartido.');
        }

        try {
            DB::transaction(function () use ($actor, $user, $pin): void {
                $user->forceFill([
                    'pin_hash' => $this->pinHasher->hash($pin),
                    'pin_enabled' => true,
                    'pin_changed_at' => now(),
                    'pin_pepper_version' => $this->pinHasher->pepperVersion(),
                    'pin_failed_attempts' => 0,
                    'pin_failed_window_started_at' => null,
                    'pin_locked_until' => null,
                    'pin_daily_failed_attempts' => 0,
                    'pin_daily_window_started_at' => now(),
                ])->save();

                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $user,
                    actor: $actor,
                    logName: 'identity',
                    event: 'user_pin_changed',
                    description: 'PIN de usuario configurado',
                    properties: ['pin_enabled' => true],
                    source: 'api',
                ));
            });
        } catch (RuntimeException) {
            throw new IdentityException(503, 'IDENTITY_NOT_CONFIGURED', 'La autenticación no está configurada.');
        }
    }

    public function disable(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user): void {
            $user->forceFill([
                'pin_hash' => null,
                'pin_enabled' => false,
                'pin_changed_at' => now(),
                'pin_pepper_version' => null,
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
                'pin_daily_failed_attempts' => 0,
            ])->save();

            $user->authSessions()->where('kind', 'shared_user')->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => 'pin_disabled',
            ]);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $actor,
                logName: 'identity',
                event: 'user_pin_disabled',
                description: 'PIN de usuario deshabilitado',
                source: 'api',
            ));
        });
    }

    public function unlock(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user): void {
            $user->forceFill([
                'pin_failed_attempts' => 0,
                'pin_failed_window_started_at' => null,
                'pin_locked_until' => null,
                'pin_daily_failed_attempts' => 0,
                'pin_daily_window_started_at' => now(),
            ])->save();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $actor,
                logName: 'identity',
                event: 'user_pin_unlocked',
                description: 'Bloqueo de PIN levantado',
                source: 'api',
            ));
        });
    }
}
