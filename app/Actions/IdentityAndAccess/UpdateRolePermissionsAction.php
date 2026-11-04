<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use App\Services\IdentityAndAccess\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateRolePermissionsAction
{
    public function __construct(private AccessControl $access, private PermissionCatalog $catalog, private AuditRecorder $audit) {}

    /** @param list<string> $permissions */
    public function execute(User $actor, Role $role, array $permissions, int $version): Role
    {
        return DB::transaction(function () use ($actor, $role, $permissions, $version): Role {
            $actor = $this->access->lockActor($actor);
            $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $role);
            $this->access->requireVersion($role, $version);
            $this->catalog->validate($permissions);
            $before = $role->permissions()->orderBy('name')->pluck('name')->all();
            sort($permissions);
            if ($before === $permissions) {
                return $role->load('permissions');
            }
            $role->syncPermissions($permissions);
            $role->version++;
            $role->save();
            foreach ($role->users()->withTrashed()->orderBy('users.id')->lockForUpdate()->get() as $user) {
                $this->access->enforceSessions($user);
            }
            $this->audit->record(AuditEntryData::forSubject(
                subject: $role, actor: $actor, logName: 'identity', event: 'role_permissions_updated',
                description: 'Permisos del rol actualizados', properties: ['result' => 'success', 'subject_snapshot' => [
                    'name' => $role->name, 'label' => $role->label, 'permissions' => $permissions,
                ]], attributeChanges: ['old' => ['permissions' => $before], 'new' => ['permissions' => $permissions]], source: 'api',
            ));
            $this->access->invalidate();

            return $role->load('permissions');
        });
    }
}
