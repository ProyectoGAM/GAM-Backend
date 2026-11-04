<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateUserRoleAction
{
    public function __construct(private AccessControl $access, private AuditRecorder $audit) {}

    public function execute(User $actor, User $user, int $roleId): User
    {
        return DB::transaction(function () use ($actor, $user, $roleId): User {
            $actor = $this->access->lockActor($actor);
            $role = Role::query()->where('guard_name', 'web')->whereKey($roleId)->lockForUpdate()->firstOrFail();
            $user = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('assignRole', $user);
            if ($role->name === 'admin' && $user->usesDevicePin()) {
                throw new IdentityException(422, 'ACCESS_METHOD_CONFLICT', 'Una cuenta con acceso PIN no puede convertirse en administradora.');
            }
            /** @var Role|null $before */
            $before = $user->roles()->first();
            if ($before?->id === $role->id) {
                return $user;
            }
            if ($role->name !== 'admin') {
                $this->access->requireAnotherAdmin($user);
            }
            $user->syncRoles([$role]);
            $this->access->enforceSessions($user);
            $this->audit->record(AuditEntryData::forSubject(
                subject: $user, actor: $actor, logName: 'identity', event: 'user_role_updated', description: 'Rol del usuario actualizado',
                properties: ['result' => 'success', 'subject_snapshot' => ['name' => $user->name], 'role_snapshot' => $role->only(['id', 'name', 'label']), 'previous_role_snapshot' => $before?->only(['id', 'name', 'label'])],
                attributeChanges: ['old' => ['role' => $before?->name], 'new' => ['role' => $role->name]], source: 'api',
            ));
            $this->access->invalidate();

            return $user->refresh();
        });
    }
}
