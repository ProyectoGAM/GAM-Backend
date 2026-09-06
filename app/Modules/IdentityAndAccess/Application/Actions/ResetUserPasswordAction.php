<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final class ResetUserPasswordAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(User $actor, User $user, string $password): void
    {
        DB::transaction(function () use ($actor, $user, $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->authSessions()->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => 'password_reset',
            ]);
            $user->tokens()->delete();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $actor,
                logName: 'identity',
                event: 'user_password_reset',
                description: 'Contraseña de usuario restablecida',
                source: 'api',
            ));
        });
    }
}
