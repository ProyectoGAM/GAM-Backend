<?php

namespace App\Modules\IdentityAndAccess\Application\Actions;

use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final class RevokeUserSessionsAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(User $actor, User $user, string $reason = 'admin_revoked'): void
    {
        DB::transaction(function () use ($actor, $user, $reason): void {
            $user->authSessions()->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);
            $user->tokens()->delete();

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $user,
                actor: $actor,
                logName: 'identity',
                event: 'user_sessions_revoked',
                description: 'Sesiones de usuario revocadas',
                properties: ['reason' => $reason],
                source: 'api',
            ));
        });
    }
}
