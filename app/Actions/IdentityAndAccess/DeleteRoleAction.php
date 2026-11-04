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

final class DeleteRoleAction
{
    public function __construct(private AccessControl $access, private AuditRecorder $audit) {}

    public function execute(User $actor, Role $role, int $version): void
    {
        DB::transaction(function () use ($actor, $role, $version): void {
            $actor = $this->access->lockActor($actor);
            $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $role);
            $this->access->requireVersion($role, $version);
            if ($role->users()->withTrashed()->exists()) {
                throw new IdentityException(409, 'ROLE_IN_USE', 'El rol tiene usuarios asignados. Reasígnalos antes de eliminarlo.');
            }
            $snapshot = [...$role->only(['name', 'label', 'description']), 'permissions' => $role->permissions()->pluck('name')->all()];
            $role->delete();
            $this->audit->record(AuditEntryData::forSubject(
                subject: $role, actor: $actor, logName: 'identity', event: 'role_deleted', description: 'Rol eliminado',
                properties: ['result' => 'success', 'subject_snapshot' => $snapshot], source: 'api',
            ));
            $this->access->invalidate();
        });
    }
}
