<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RevokeUserSessionsAction
{
    public function __construct(private AuditRecorder $auditRecorder, private AccessControl $access) {}

    public function execute(User $actor, User $user, string $reason = 'admin_revoked'): void
    {
        DB::transaction(function () use ($actor, $user, $reason): void {
            $actor = $this->access->lockActor($actor);
            $user = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('revokeSessions', $user);
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
