<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ResetUserPasswordAction
{
    public function __construct(private AuditRecorder $auditRecorder, private AccessControl $access) {}

    public function execute(User $actor, User $user, string $password): void
    {
        DB::transaction(function () use ($actor, $user, $password): void {
            $actor = $this->access->lockActor($actor);
            $user = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $user);
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
