<?php

namespace App\Actions\IdentityAndAccess;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Services\IdentityAndAccess\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateRoleAction
{
    public function __construct(private AccessControl $access, private AuditRecorder $audit) {}

    /** @param array{nombre?: string, descripcion?: ?string, version: int} $data */
    public function execute(User $actor, Role $role, array $data): Role
    {
        return DB::transaction(function () use ($actor, $role, $data): Role {
            $actor = $this->access->lockActor($actor);
            $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $role);
            $this->access->requireVersion($role, (int) $data['version']);
            $before = $role->only(['label', 'description']);
            $role->fill([
                'label' => $data['nombre'] ?? $role->label,
                'description' => array_key_exists('descripcion', $data) ? $data['descripcion'] : $role->description,
            ]);
            if (! $role->isDirty()) {
                return $role->load('permissions');
            }
            $role->version++;
            $role->save();
            $after = $role->only(['label', 'description']);
            $this->audit->record(AuditEntryData::forSubject(
                subject: $role, actor: $actor, logName: 'identity', event: 'role_updated', description: 'Rol actualizado',
                properties: ['result' => 'success', 'subject_snapshot' => ['name' => $role->name, ...$after]], attributeChanges: ['old' => $before, 'new' => $after], source: 'api',
            ));

            return $role->load('permissions');
        });
    }
}
