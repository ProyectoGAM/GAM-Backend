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
use Illuminate\Support\Str;

final class CreateRoleAction
{
    public function __construct(private AccessControl $access, private PermissionCatalog $catalog, private AuditRecorder $audit) {}

    /** @param array{nombre: string, descripcion?: ?string, permisos: list<string>} $data */
    public function execute(User $actor, array $data): Role
    {
        return DB::transaction(function () use ($actor, $data): Role {
            $actor = $this->access->lockActor($actor);
            Gate::forUser($actor)->authorize('create', Role::class);
            $this->catalog->validate($data['permisos']);
            $role = Role::query()->create([
                'name' => 'custom_'.Str::lower((string) Str::ulid()), 'guard_name' => 'web',
                'label' => $data['nombre'], 'description' => $data['descripcion'] ?? null,
            ]);
            $role->syncPermissions($data['permisos']);
            $this->audit->record(AuditEntryData::forSubject(
                subject: $role, actor: $actor, logName: 'identity', event: 'role_created',
                description: 'Rol creado', properties: ['result' => 'success', 'subject_snapshot' => [
                    'name' => $role->name, 'label' => $role->label, 'description' => $role->description,
                    'permissions' => $data['permisos'],
                ]], attributeChanges: ['old' => ['permissions' => []], 'new' => ['permissions' => $data['permisos']]], source: 'api',
            ));
            $this->access->invalidate();

            return $role->refresh()->load('permissions');
        });
    }
}
