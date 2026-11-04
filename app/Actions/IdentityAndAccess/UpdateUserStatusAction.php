<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateUserStatusAction
{
    public function __construct(private AccessControl $access, private AuditRecorder $audit) {}

    public function execute(User $actor, User $user, bool $enabled): User
    {
        return DB::transaction(function () use ($actor, $user, $enabled): User {
            $actor = $this->access->lockActor($actor);
            $user = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $user);
            $before = ! $user->trashed();
            if ($before === $enabled) {
                return $user;
            }
            if ($enabled) {
                $user->restore();
            } else {
                $this->access->requireAnotherAdmin($user);
                $user->authSessions()->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'user_disabled']);
                $user->tokens()->delete();
                $user->delete();
            }
            $this->audit->record(AuditEntryData::forSubject(
                subject: $user, actor: $actor, logName: 'identity', event: 'user_status_updated', description: 'Estado del usuario actualizado',
                properties: ['result' => 'success', 'subject_snapshot' => ['name' => $user->name]],
                attributeChanges: ['old' => ['enabled' => $before], 'new' => ['enabled' => $enabled]], source: 'api',
            ));

            return $user->refresh();
        });
    }
}
